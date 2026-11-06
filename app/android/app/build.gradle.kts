import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing: android/key.properties (never in git: android/.gitignore) names the upload keystore — app/README.md
// «بناء نسخ المتاجر». Without it a release build is signed with the debug key: it runs on a test phone, and Google
// Play refuses it.
val keystoreProperties = Properties().apply {
    rootProject.file("key.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
}

// Push (Firebase): google-services.json comes from the Firebase project (docs/integrations.md «الإشعارات —
// Firebase»). Without it the app builds and runs without push.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

android {
    namespace = "com.zon.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.zon.app"
        // Android 8.0 or newer: Sentry's Android SDK (sentry_flutter 10) needs it; Flutter's default is 24.
        minSdk = 26
        targetSdk = flutter.targetSdkVersion
        // The version and its build number from pubspec.yaml (`version: x.y.z+build`).
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (!keystoreProperties.isEmpty) {
            create("release") {
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName(if (keystoreProperties.isEmpty) "debug" else "release")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
