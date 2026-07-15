class EventScheduleDay {
  const EventScheduleDay({
    this.id,
    required this.dayNumber,
    this.date,
    this.startTime,
    this.endTime,
  });

  final String? id;
  final int dayNumber;
  final DateTime? date;
  final String? startTime;
  final String? endTime;

  factory EventScheduleDay.fromJson(Map<String, dynamic> json) {
    return EventScheduleDay(
      id: json['id']?.toString(),
      dayNumber: int.tryParse(json['day_number']?.toString() ?? '') ?? 1,
      date: _parseDate(json['date']),
      startTime: _stringOrNull(json['start_time']),
      endTime: _stringOrNull(json['end_time']),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'day_number': dayNumber,
      'date': date?.toIso8601String(),
      'start_time': startTime,
      'end_time': endTime,
    };
  }

  String get dateLabel {
    final value = date;
    if (value == null) return 'Date TBA';
    return '${_monthName(value.month)} ${value.day}, ${value.year}';
  }

  String get timeRangeLabel {
    final start = startTimeLabel;
    final end = endTimeLabel;
    if (start == null && end == null) return 'Time TBA';
    if (start == null) return 'Until $end';
    if (end == null) return 'From $start';
    return '$start - $end';
  }

  String? get startTimeLabel => _formatTime(startTime);

  String? get endTimeLabel => _formatTime(endTime);

  String get compactLabel => 'D$dayNumber: $dateLabel, $timeRangeLabel';

  static DateTime? _parseDate(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }

  static String? _stringOrNull(Object? value) {
    if (value == null) return null;
    final text = value.toString();
    return text.trim().isEmpty ? null : text;
  }

  static String? _formatTime(String? value) {
    if (value == null || value.trim().isEmpty) return null;
    final parts = value.split(':');
    if (parts.length < 2) return value;

    final hour = int.tryParse(parts[0]);
    final minute = int.tryParse(parts[1]);
    if (hour == null || minute == null) return value;

    final suffix = hour >= 12 ? 'PM' : 'AM';
    final displayHour = hour % 12 == 0 ? 12 : hour % 12;
    return '$displayHour:${minute.toString().padLeft(2, '0')} $suffix';
  }

  static String _monthName(int month) {
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];

    if (month < 1 || month > 12) return 'Date';
    return months[month - 1];
  }
}

List<EventScheduleDay> eventScheduleDaysFromJson(Object? value) {
  if (value is! List) {
    return const [];
  }

  final days =
      value
          .whereType<Map>()
          .map(
            (item) =>
                EventScheduleDay.fromJson(Map<String, dynamic>.from(item)),
          )
          .toList()
        ..sort((a, b) => a.dayNumber.compareTo(b.dayNumber));

  return List.unmodifiable(days);
}
