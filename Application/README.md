# USM EAES Scanner (Flutter)

Offline-first QR scanner companion for the **USM Event Attendance & Evaluation System**. Product scope is defined in the repo root [`PRD_V1.md`](../PRD_V1.md) (Epic 3, Section 3).

## Prerequisites

- [Flutter SDK](https://docs.flutter.dev/get-started/install) (stable; tested with 3.38.x)
- Android Studio / Xcode for device emulators (optional: Windows desktop for UI dev)
- Laravel API running from [`../Web/`](../Web/) when integrating sync

## Quick start

```powershell
cd Application
flutter pub get
flutter run
```

### API base URL (local backend)

Default: `http://localhost:8000/api`. Start Laravel from `../Web/` with `php artisan serve --host=127.0.0.1 --port=8000` before testing scanner sync.

| Target | Example `API_BASE_URL` |
|--------|-------------------------|
| Windows desktop | `http://localhost:8000/api` |
| Android emulator | `http://10.0.2.2:8000/api` |
| Physical device (same LAN) | `http://<your-pc-ip>:8000/api` and run Laravel with `--host=0.0.0.0` |

```powershell
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

### Google sign-in (Android)

The Android scanner uses `google_sign_in` through Android Credential Manager. The app package is `edu.usm.eaes.eaes_scanner`; register that package in Google Cloud or Firebase with the debug and release SHA-1 fingerprints for the build you install on the phone.

- Pass the web OAuth client ID to Flutter as `GOOGLE_SERVER_CLIENT_ID`. Android uses this as the server client ID for ID-token issuance.
- Do not pass an Android OAuth client ID as `GOOGLE_CLIENT_ID` for Android builds; Android identifies the app from package name plus signing SHA-1.
- `GOOGLE_HOSTED_DOMAIN` defaults to empty on Android. Leave it empty unless a specific Google Workspace picker filter is required; Laravel still enforces the `@usm.edu.ph` account rule after Google returns an ID token.
- For this workstation's debug build, register SHA-1 `1E:F4:E2:DA:ED:C6:4F:33:E4:53:AE:32:03:6F:75:D9:CB:28:40:5A` with Android package `edu.usm.eaes.eaes_scanner` in Google Cloud or Firebase.
- In Laravel, set `GOOGLE_MOBILE_CLIENT_IDS` to the token audience values accepted from the mobile app. This should include the web client ID used as `GOOGLE_SERVER_CLIENT_ID` unless the Google project issues a different mobile token audience.

```powershell
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api --dart-define=GOOGLE_SERVER_CLIENT_ID=<web-oauth-client-id>
```

## Scanner access (Epic 3.1)

Scanner access is account-based and assignment-scoped. An admin assigns an eligible student/officer account to an approved event, the scanner logs in with their institutional account, selects an assigned event, and activates it. Activation creates the device-bound scanner session token used internally for roster hydration, offline capture, and sync.

## Project layout

```text
lib/
  app/           # MaterialApp shell
  config/        # dart-define configuration
  features/
    scanner/     # Scanner UI, session validation, sync, SQLite
```

## Scanner dependencies

| Package | PRD use |
|---------|---------|
| `sqflite` | Offline SQLite (§3, Feature 3.3) |
| `mobile_scanner` | QR capture (Epic 3.2) |
| `connectivity_plus` | Sync triggers (§3.1) |
| `http` | Bulk scan sync API (§3.3) |

## Commands

```powershell
flutter analyze
flutter test
flutter build apk --debug
```

## Manual E2E check

1. Start Laravel from `../Web/`.
2. Log in to the web dashboard, approve or use an approved event, and assign a scanner from `/dashboard/events`.
3. Log in to the Flutter app with the assigned institutional account.
4. Select the assigned event and tap **Activate event**.
5. Hydrate the roster, scan a registered QR, scan an unknown QR, save a manual QR, then sync.
6. Confirm Laravel has raw scans, attendance records for matched students, and pending QR records for unknown values.
