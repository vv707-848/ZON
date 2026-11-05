<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Models\Media;
use App\Support\MediaLibrary;
use App\Support\Photos\Photos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A photo is kept as the platform redraws it, never as it left the phone: upright, without its metadata (the
 * location first), in three sizes — and the files of a copy outlive the deletion of the original record.
 */
class PhotosTest extends TestCase
{
    /**
     * A phone's JPEG of $w × $h with an EXIF block: the camera's orientation and a GPS position. Red marks the
     * stored top-left corner, so where it lands after cleaning shows the turn.
     */
    private function phonePhoto(int $w, int $h, int $orientation): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, intdiv($w, 4), intdiv($h, 4), (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // TIFF, big-endian: IFD0 {Orientation, pointer to the GPS IFD at 38} → GPS IFD {GPSLatitudeRef "N"}.
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('nnN', $tag, $type, $count).str_pad($value, 4, "\0");
        $ifd0 = pack('n', 2).$entry(0x0112, 3, 1, pack('n', $orientation)).$entry(0x8825, 4, 1, pack('N', 38)).pack('N', 0);
        $gps = pack('n', 1).$entry(0x0001, 2, 2, "N\0").pack('N', 0);
        $app1 = "Exif\0\0"."MM\0*".pack('N', 8).$ifd0.$gps;

        $path = tempnam(sys_get_temp_dir(), 'phone');
        file_put_contents($path, "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2));
        $this->assertSame('N', exif_read_data($path, null, true)['GPS']['GPSLatitudeRef'] ?? null, 'the sample carries a location');

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    /** @return array{0: int, 1: int, 2: int} red, green, blue of a pixel of a stored file */
    private function pixel(string $path, int $x, int $y): array
    {
        $image = imagecreatefromstring(Storage::disk('minio')->get($path));
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post('/api/v1/media', ['photo' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_phone_photo_is_kept_upright_without_its_location_in_three_sizes(): void
    {
        $this->signIn(Customer::factory()->broker()->create());

        $data = $this->upload($this->phonePhoto(3000, 2000, 6))->assertCreated()->json('data');

        $media = Media::query()->findOrFail($data['id']);
        $this->assertSame([1365, 2048], [$media->width, $media->height], 'turned a quarter clockwise, then fitted to 2048');
        $this->assertSame(['large', 'card'], $media->sizes);
        foreach (['main' => [1365, 2048], 'large' => [853, 1280], 'card' => [427, 640]] as $size => $dimensions) {
            $bytes = Storage::disk('minio')->get($media->pathOf($size));
            $this->assertSame($dimensions, array_slice(getimagesizefromstring($bytes), 0, 2), $size);
            $this->assertStringNotContainsString('Exif', $bytes, "{$size}: no metadata block left");
        }
        [$r, $g, $b] = $this->pixel($media->path, 1365 - 20, 20);
        $this->assertTrue($r > 200 && $g < 60 && $b < 60, 'the stored top-left is now the top-right: the photo stands as it was taken');
        $this->assertSame(['image/jpeg', strlen(Storage::disk('minio')->get($media->path))], [$media->mime, $media->size]);
        $this->assertStringContainsString('-card.jpg', $data['card_url']);
        $this->assertStringContainsString('-large.jpg', $data['large_url']);
    }

    /** @return array<string, array{int, string}> EXIF orientation => where the stored top-left corner must end up */
    public static function orientations(): array
    {
        return ['1 as taken' => [1, 'top-left'], '2 mirrored' => [2, 'top-right'], '3 upside down' => [3, 'bottom-right'],
            '4 mirrored upside down' => [4, 'bottom-left'], '5 transposed' => [5, 'top-left'], '6 quarter clockwise' => [6, 'top-right'],
            '7 transversed' => [7, 'bottom-right'], '8 quarter anticlockwise' => [8, 'bottom-left']];
    }

    #[DataProvider('orientations')]
    public function test_every_camera_orientation_stands_the_photo_up(int $orientation, string $corner): void
    {
        $photo = Photos::clean((string) $this->phonePhoto(400, 300, $orientation)->getRealPath());

        $image = imagecreatefromstring($photo->main());
        [$w, $h] = [imagesx($image), imagesy($image)];
        $this->assertSame($orientation >= 5 ? [300, 400] : [400, 300], [$w, $h]);
        [$x, $y] = match ($corner) {
            'top-left' => [5, 5], 'top-right' => [$w - 6, 5], 'bottom-right' => [$w - 6, $h - 6], default => [5, $h - 6],
        };
        $rgb = imagecolorat($image, $x, $y);
        $this->assertTrue((($rgb >> 16) & 0xFF) > 200 && ($rgb & 0xFF) < 60, "red at the {$corner}");
    }

    public function test_a_transparent_png_is_laid_on_white_and_a_small_photo_is_never_enlarged(): void
    {
        $this->signIn(Customer::factory()->owner()->create());
        $image = imagecreatetruecolor(300, 200);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $path);

        $id = $this->upload(new UploadedFile($path, 'plan.png', 'image/png', null, true))->assertCreated()->json('data.id');

        $media = Media::query()->findOrFail($id);
        $this->assertSame([300, 200], [$media->width, $media->height]);
        $this->assertStringEndsWith('.jpg', $media->path);
        $this->assertSame([255, 255, 255], $this->pixel($media->pathOf('card'), 150, 100), 'white, not the black GD would paint');
    }

    public function test_a_photo_too_large_to_redraw_is_refused_with_a_reason(): void
    {
        config(['zon.media.max_megapixels' => 0.5]);
        $this->signIn(Customer::factory()->broker()->create());

        $this->upload(UploadedFile::fake()->image('big.jpg', 1000, 600))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.photo.0', 'أبعاد الصورة أكبر من المسموح (0.5 ميغابكسل). صغّرها ثم أعد رفعها.');
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('minio')->allFiles());
    }

    public function test_cards_show_the_card_size_and_a_copy_keeps_its_files_when_the_original_goes(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $id = $this->upload($this->phonePhoto(1200, 900, 1))->assertCreated()->json('data.id');
        $item = InventoryItem::query()->create(['broker_id' => $broker->id, 'purpose' => 'sale', 'property_type' => 'apartment',
            'title' => 'شقة في النرجس', 'city' => 'الرياض', 'neighborhood' => 'النرجس', 'price' => 900000]);
        MediaLibrary::sync($item, $broker, [$id]);
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        MediaLibrary::copy($item, $listing);
        $copy = Media::query()->where('mediable_type', $listing->getMorphClass())->sole();

        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk()->assertJsonPath('data.photos.0.id', $copy->id);
        $this->assertStringContainsString('-card.jpg', (string) $this->getJson('/api/v1/listings')->json('data.0.cover_url'));

        $this->deleteJson("/api/v1/media/{$id}")->assertOk();
        foreach ($copy->paths() as $path) {
            Storage::disk('minio')->assertExists($path);
        }
        $copy->deleteWithFiles();
        $this->assertSame([], Storage::disk('minio')->allFiles(), 'the last record takes its files with it');
    }
}
