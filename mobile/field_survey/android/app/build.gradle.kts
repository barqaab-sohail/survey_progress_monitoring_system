plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Build an unsigned artifact while the installed app's original key is unavailable.
// Use an uppercase environment name: Flutter normalizes Windows environment keys.
// Opt in with HAZECO_UNSIGNED_APK=true; never create a substitute key.
val unsignedApk = providers.gradleProperty("hazecoUnsignedApk")
    .orElse(providers.environmentVariable("HAZECO_UNSIGNED_APK"))
    .orNull == "true"

if (unsignedApk) {
    logger.lifecycle("HAZECO unsigned release: signing disabled.")
}

android {
    namespace = "com.barqaab.hazeco.hazeco_field_survey"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.barqaab.hazeco.hazeco_field_survey"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = 24
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // Development build only. Configure an organization-owned signing
            // key before publishing or distributing a production release.
            signingConfig = if (unsignedApk) null else signingConfigs.getByName("debug")
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
