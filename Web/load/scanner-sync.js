import http from 'k6/http';
import { check, sleep } from 'k6';

const tokens = (__ENV.K6_SCANNER_TOKENS || '').split(',').filter(Boolean);
const eventId = __ENV.K6_EVENT_ID;
const qrCodeValue = __ENV.K6_QR_CODE_VALUE;
const scannedAt = __ENV.K6_SCANNED_AT;

if (__ENV.K6_ALLOW_LOAD_TEST !== 'true') {
  throw new Error('Set K6_ALLOW_LOAD_TEST=true only for an isolated load-test environment.');
}

if (!eventId || !qrCodeValue || !scannedAt || tokens.length < 20) {
  throw new Error('K6_EVENT_ID, K6_QR_CODE_VALUE, K6_SCANNED_AT, and 20 scanner tokens are required.');
}

export const options = {
  scenarios: {
    scanner_sync: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 20 },
        { duration: '5m', target: 20 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<2000'],
    http_req_failed: ['rate<0.01'],
  },
};

export default function () {
  const token = tokens[(__VU - 1) % tokens.length];
  const scans = Array.from({ length: 200 }, (_, index) => ({
    dedup_key: `k6-${__VU}-${__ITER}-${index}`,
    scan_type: 'time_in',
    scanned_at: scannedAt,
    device_id: `k6-scanner-${__VU}`,
    manual_entry: false,
    qr_code_value: qrCodeValue,
  }));

  const response = http.post(
    `${__ENV.K6_BASE_URL}/api/v1/attendance/sync`,
    JSON.stringify({ event_id: eventId, scans }),
    {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    },
  );

  check(response, {
    'sync accepted': (result) => result.status === 200,
    'all scans ingested': (result) => result.json('ingested_count') === 200,
  });

  sleep(30);
}
