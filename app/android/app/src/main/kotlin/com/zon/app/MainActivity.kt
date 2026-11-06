package com.zon.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // The channel FCM shows the account's notifications in (AndroidManifest: default_notification_channel_id).
        // Creating it again is a no-op; the person may change its sound or turn it off in the phone's settings.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel("zon", getString(R.string.notification_channel_name), NotificationManager.IMPORTANCE_HIGH)
            channel.description = getString(R.string.notification_channel_description)
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }
}
