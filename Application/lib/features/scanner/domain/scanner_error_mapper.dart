import 'dart:io';

import '../data/scanner_api_client.dart';

enum ScannerErrorCategory {
  networkError,
  unauthorized,
  expiredSession,
  serverError,
  validationError,
  unknownError,
}

class MappedScannerError {
  const MappedScannerError({
    required this.message,
    required this.category,
    required this.technicalDetails,
  });

  final String message;
  final ScannerErrorCategory category;
  final String technicalDetails;

  String get categoryKey {
    switch (category) {
      case ScannerErrorCategory.networkError:
        return 'network_error';
      case ScannerErrorCategory.unauthorized:
        return 'unauthorized';
      case ScannerErrorCategory.expiredSession:
        return 'expired_session';
      case ScannerErrorCategory.serverError:
        return 'server_error';
      case ScannerErrorCategory.validationError:
        return 'validation_error';
      case ScannerErrorCategory.unknownError:
        return 'unknown_error';
    }
  }
}

class ScannerErrorMapper {
  ScannerErrorMapper._();

  static MappedScannerError map(Object error) {
    final technical = error.toString();

    if (error is ScannerApiException) {
      return _mapApiException(error, technical);
    }

    if (error is SocketException || _isNetworkError(technical)) {
      return MappedScannerError(
        message:
            'Unable to connect to the server. Check your internet connection '
            'or ask the admin to confirm your scanner assignment.',
        category: ScannerErrorCategory.networkError,
        technicalDetails: technical,
      );
    }

    return MappedScannerError(
      message: 'Something went wrong. Please try again or contact the admin.',
      category: ScannerErrorCategory.unknownError,
      technicalDetails: technical,
    );
  }

  static MappedScannerError mapSessionActivation(Object error) {
    final mapped = map(error);
    if (mapped.category != ScannerErrorCategory.unknownError) {
      return mapped;
    }

    if (error is ScannerApiException) {
      return mapped;
    }

    return MappedScannerError(
      message: 'This scanner session is invalid.',
      category: ScannerErrorCategory.validationError,
      technicalDetails: mapped.technicalDetails,
    );
  }

  static MappedScannerError _mapApiException(
    ScannerApiException error,
    String technical,
  ) {
    final status = error.statusCode;
    final lower = error.message.toLowerCase();

    if (status == 401) {
      if (lower.contains('revoked')) {
        return MappedScannerError(
          message: 'This scanner session has been revoked by an admin.',
          category: ScannerErrorCategory.expiredSession,
          technicalDetails: technical,
        );
      }

      if (lower.contains('expired')) {
        return MappedScannerError(
          message:
              'Scanner session expired. Contact admin if you still have unsynced scans.',
          category: ScannerErrorCategory.expiredSession,
          technicalDetails: technical,
        );
      }

      return MappedScannerError(
        message: 'This scanner session is expired or unauthorized.',
        category: ScannerErrorCategory.expiredSession,
        technicalDetails: technical,
      );
    }

    if (status == 403) {
      if (lower.contains('boundary') || lower.contains('authorized')) {
        return MappedScannerError(
          message: 'You are not authorized to scan for this event.',
          category: ScannerErrorCategory.unauthorized,
          technicalDetails: technical,
        );
      }
      return MappedScannerError(
        message: 'This scanner session is expired or unauthorized.',
        category: ScannerErrorCategory.unauthorized,
        technicalDetails: technical,
      );
    }

    if (status == 404) {
      return MappedScannerError(
        message: 'This event session could not be found.',
        category: ScannerErrorCategory.validationError,
        technicalDetails: technical,
      );
    }

    if (status == 422) {
      if (lower.contains('approved') || lower.contains('locked')) {
        return MappedScannerError(
          message: 'This event is not approved for scanning yet.',
          category: ScannerErrorCategory.validationError,
          technicalDetails: technical,
        );
      }
      return MappedScannerError(
        message: 'This scanner session is invalid.',
        category: ScannerErrorCategory.validationError,
        technicalDetails: technical,
      );
    }

    if (status == 423) {
      if (lower.contains('completed')) {
        return MappedScannerError(
          message:
              'Event completed. Sync any confirmed pending scans, then the local roster will be removed.',
          category: ScannerErrorCategory.validationError,
          technicalDetails: technical,
        );
      }

      return MappedScannerError(
        message: 'This event is not approved for scanning yet.',
        category: ScannerErrorCategory.validationError,
        technicalDetails: technical,
      );
    }

    if (status == 429 || status == 503) {
      return MappedScannerError(
        message: 'The server is busy. Your scans remain saved and will retry shortly.',
        category: ScannerErrorCategory.serverError,
        technicalDetails: technical,
      );
    }

    if (status != null && status >= 500) {
      return MappedScannerError(
        message: 'Server error. Please try again or contact the admin.',
        category: ScannerErrorCategory.serverError,
        technicalDetails: technical,
      );
    }

    return MappedScannerError(
      message: 'Something went wrong. Please try again or contact the admin.',
      category: ScannerErrorCategory.unknownError,
      technicalDetails: technical,
    );
  }

  static bool _isNetworkError(String technical) {
    final lower = technical.toLowerCase();
    return lower.contains('failed host lookup') ||
        lower.contains('clientexception') ||
        lower.contains('socketexception') ||
        lower.contains('connection refused') ||
        lower.contains('network is unreachable') ||
        lower.contains('timed out');
  }
}
