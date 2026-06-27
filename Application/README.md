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

## Deep links (Epic 3.1)

Custom scheme registered on Android and iOS:

```text
eaes://scanner?token=<session-token>&event_id=<event-uuid>
```

The Laravel dashboard generates this link for approved or completed events. The Flutter app validates the token, hydrates the event roster, saves known and unknown QR scans offline, and syncs pending scans to the Laravel API when the device is online.

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
| `app_links` | Deep-linked scanner sessions (Feature 3.1) |
| `http` | Bulk scan sync API (§3.3) |

## Commands

```powershell
flutter analyze
flutter test
flutter build apk --debug
```

## Manual E2E check

1. Start Laravel from `../Web/`.
2. Log in to the web dashboard and approve or use an approved event.
3. Generate a scanner link from `/dashboard/events`.
4. Paste the `eaes://scanner?...` link into the Flutter app or open it on a device with the app installed.
5. Hydrate the roster, scan a registered QR, scan an unknown QR, save a manual QR, then sync.
6. Confirm Laravel has raw scans, attendance records for matched students, and pending QR records for unknown values.
