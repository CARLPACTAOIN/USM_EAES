enum ScanType {
  timeIn,
  timeOut;

  String get apiValue {
    switch (this) {
      case ScanType.timeIn:
        return 'time_in';
      case ScanType.timeOut:
        return 'time_out';
    }
  }

  String get label {
    switch (this) {
      case ScanType.timeIn:
        return 'Time-In';
      case ScanType.timeOut:
        return 'Time-Out';
    }
  }
}
