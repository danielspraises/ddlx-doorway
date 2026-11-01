# DDLX Vendor Hub: Android app (Capacitor)

A thin native app around **https://vendor.ddlx.shop**. The app opens your live site in a full-screen window and adds real phone features: push notifications (new orders, messages, payouts), tapping a notification opens the right page, camera and gallery for product photos, and a Play Store listing.

Because it loads the live site, **website updates reach the app instantly**. You only build a new app when you change something in this `mobile` folder.

> Status: the server side (push sending, phone registration, admin settings) is built and tested. This `mobile` project could not be built where it was written (no Android tools there), so the first build on your PC is the real test. If a command fails, send me the exact message.

## What you need (once, on your Windows PC)
1. **Node.js 20 or newer** (nodejs.org, the LTS installer).
2. **Android Studio** (developer.android.com/studio). On first start let it install the Android SDK. It also includes the Java you need.
3. A **Google account** for Firebase (free) and a **Google Play Developer account** ($25 once) when you publish.
4. An Android phone with **Developer options → USB debugging** switched on, for testing.

## Part 1: Firebase (free), for notifications
1. Go to console.firebase.google.com → **Add project** → name it "DDLX Vendor Hub" (turn Google Analytics off).
2. In the project: **Add app → Android**. Package name must be exactly `shop.ddlx.vendorhub`. App nickname: Vendor Hub. Skip the SHA field for now. Click Register.
3. **Download `google-services.json`** and keep it. Do not commit it (it is in `.gitignore`).
4. Project settings (gear icon) → **Service accounts** → **Generate new private key** → download the JSON file.
5. In Vendor Hub: **Admin → Settings → Phone app notifications (Firebase)** → paste the whole contents of that private key file → Save.

## Part 2: Build the app on your PC
Open PowerShell in this `mobile` folder.

```powershell
npm install
npx cap add android
```

Copy `google-services.json` into `mobile\android\app\`.

Make the icons and launch screen from the pictures in `assets\`:

```powershell
npm run assets
```

Add two permissions. Open `android\app\src\main\AndroidManifest.xml` and, inside `<manifest>` and above `<application>`, make sure these lines exist (add any that are missing):

```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
<uses-permission android:name="android.permission.CAMERA" />
<uses-feature android:name="android.hardware.camera" android:required="false" />
```

Then:

```powershell
npx cap sync android
npx cap open android
```

Android Studio opens. Wait for the first "Gradle sync" to finish (a few minutes), connect your phone, choose it in the device list at the top and press the green **Run** button.

## Part 3: Test it
1. The app opens Vendor Hub. Sign in. Android asks to allow notifications: choose **Allow**.
2. As admin, open **Admin → Settings → Phone app notifications → Send me a test notification**. The phone should buzz within seconds.
3. Ask a test seller to place a test order. The seller's phone gets "New order" and tapping it opens their Sales tab.
4. In **Products → Add photo**, check that you can choose Camera or Gallery.
5. Sign out in the app, then sign in as someone else: the first person must stop receiving alerts on that phone.

If no notification arrives: the package name in Firebase must be exactly `shop.ddlx.vendorhub`, `google-services.json` must be inside `android\app\`, and the service-account key must be from the same Firebase project.

## Part 4: Publish on Google Play
1. **Create a signing key** (keep this file and its passwords safe forever; losing them means you cannot update the app). In PowerShell:
   `keytool -genkey -v -keystore ddlx-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias ddlx`
2. In Android Studio: **Build → Generate Signed App Bundle / APK → Android App Bundle** → choose the key → **release**. The result is an `.aab` file.
3. play.google.com/console → **Create app** (name "DDLX Vendor Hub", free). Complete the dashboard tasks: store listing (description, icon 512 px, at least 2 phone screenshots, a 1024 x 500 feature picture), privacy policy link, content rating, data safety form (collects email, orders, uploaded photos), target audience.
4. Start with an **Internal testing** release: upload the `.aab`, add testers' emails, share the opt-in link, check it on a few phones.
5. Then **Production** → create release → upload the same or a newer `.aab` → submit for review (usually a few days).
   Note: Google may require a number of testers to test for a period before production on new personal developer accounts. The console tells you.
6. For each new version, raise `versionCode` (and `versionName`) in `android\app\build.gradle` and build a new bundle.

## Part 5: iPhone (later)
Needs a Mac, Xcode and an Apple Developer account ($99 a year). Steps: `npx cap add ios`, add the iOS app in Firebase, upload an APNs key in Firebase, enable the Push Notifications and Background Modes capabilities in Xcode, then archive and upload to App Store Connect. Apple reviews apps more strictly than Google; the push notifications, camera upload and your login give it real native value. Ask me when you are ready and I will write the exact steps.

## Updating and changing things
- Change the website: upload the files as usual. The app shows it immediately.
- Change the app name, icon, address or plugins: edit this folder, then `npx cap sync android` and make a new release.
- The app address is set in `capacitor.config.json` (`server.url`).
