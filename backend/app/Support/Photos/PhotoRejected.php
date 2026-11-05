<?php

namespace App\Support\Photos;

use RuntimeException;

/** A photo that cannot be kept; the message is shown to the user under the photo field. */
final class PhotoRejected extends RuntimeException {}
