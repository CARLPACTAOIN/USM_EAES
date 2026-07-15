import 'dart:async';

import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';
import '../../domain/roster_student.dart';
import '../scanner_controller.dart';

class HydratedRosterScreen extends StatefulWidget {
  const HydratedRosterScreen({super.key, required this.controller});

  final ScannerController controller;

  @override
  State<HydratedRosterScreen> createState() => _HydratedRosterScreenState();
}

class _HydratedRosterScreenState extends State<HydratedRosterScreen> {
  static const int _pageSize = 100;

  final TextEditingController _searchController = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  final List<RosterStudent> _students = [];

  Timer? _searchDebounce;
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  String _query = '';

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_handleControllerChanged);
    _scrollController.addListener(_handleScroll);
    _loadInitial();
  }

  @override
  void dispose() {
    widget.controller.removeListener(_handleControllerChanged);
    _scrollController.removeListener(_handleScroll);
    _searchDebounce?.cancel();
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _handleControllerChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  void _handleScroll() {
    if (!_scrollController.hasClients ||
        _loading ||
        _loadingMore ||
        !_hasMore) {
      return;
    }

    final position = _scrollController.position;
    if (position.pixels >= position.maxScrollExtent - 240) {
      _loadMore();
    }
  }

  Future<void> _loadInitial() async {
    setState(() {
      _loading = true;
      _hasMore = true;
    });

    final students = await widget.controller.rosterStudents(
      query: _query,
      limit: _pageSize,
    );

    if (!mounted) return;
    setState(() {
      _students
        ..clear()
        ..addAll(students);
      _hasMore = students.length == _pageSize;
      _loading = false;
    });
  }

  Future<void> _loadMore() async {
    setState(() => _loadingMore = true);

    final students = await widget.controller.rosterStudents(
      query: _query,
      limit: _pageSize,
      offset: _students.length,
    );

    if (!mounted) return;
    setState(() {
      _students.addAll(students);
      _hasMore = students.length == _pageSize;
      _loadingMore = false;
    });
  }

  void _onSearchChanged(String value) {
    setState(() => _query = value);
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 250), _loadInitial);
  }

  Future<void> _refreshRoster() async {
    await widget.controller.hydrateRoster();
    await _loadInitial();
  }

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final isDark = c.isDark;

    return Container(
      decoration: BoxDecoration(
        gradient: RadialGradient(
          center: const Alignment(0.0, -0.6),
          radius: 1.4,
          colors: [c.gradientStart, c.gradientMid, c.gradientEnd],
          stops: const [0.0, 0.45, 1.0],
        ),
      ),
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          backgroundColor: Colors.transparent,
          elevation: 0,
          foregroundColor: isDark ? Colors.white : AppColors.brandGreenDark,
          titleTextStyle: TextStyle(
            fontFamily: 'Inter',
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: isDark ? Colors.white : AppColors.brandGreenDark,
          ),
          title: const Text('Hydrated roster'),
          actions: [
            IconButton(
              tooltip: widget.controller.busy
                  ? 'Refreshing roster'
                  : 'Refresh roster',
              onPressed: widget.controller.busy ? null : _refreshRoster,
              icon: widget.controller.busy
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.refresh_rounded),
            ),
          ],
        ),
        body: SafeArea(
          top: false,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _RosterHeader(controller: widget.controller),
                    const SizedBox(height: 14),
                    TextField(
                      controller: _searchController,
                      onChanged: _onSearchChanged,
                      textInputAction: TextInputAction.search,
                      decoration: InputDecoration(
                        labelText: 'Search hydrated roster',
                        prefixIcon: const Icon(Icons.search_rounded),
                        suffixIcon: _query.trim().isEmpty
                            ? null
                            : IconButton(
                                tooltip: 'Clear search',
                                onPressed: () {
                                  _searchController.clear();
                                  _onSearchChanged('');
                                },
                                icon: const Icon(Icons.close_rounded),
                              ),
                        filled: true,
                        fillColor: c.inputFill,
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(16),
                          borderSide: BorderSide(color: c.inputBorder),
                        ),
                        focusedBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(16),
                          borderSide: BorderSide(
                            color: c.inputBorderFocused,
                            width: 1.5,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(child: _buildRosterBody(context)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildRosterBody(BuildContext context) {
    final c = AppColors.of(context);

    if (_loading) {
      return Center(
        child: CircularProgressIndicator(
          color: c.isDark ? AppColors.usmGold : AppColors.brandGreen,
        ),
      );
    }

    if (widget.controller.rosterCount == 0) {
      return _RosterEmptyState(
        icon: Icons.cloud_download_outlined,
        title: 'Roster not downloaded',
        message: 'Hydrate the participant roster before offline scanning.',
        actionLabel: widget.controller.busy
            ? 'Downloading...'
            : 'Hydrate Roster',
        onAction: widget.controller.busy ? null : _refreshRoster,
      );
    }

    if (_students.isEmpty) {
      return const _RosterEmptyState(
        icon: Icons.manage_search_rounded,
        title: 'No matching students',
        message: 'Try a different name, program, or student ID number.',
      );
    }

    return RefreshIndicator(
      onRefresh: _refreshRoster,
      child: ListView.builder(
        controller: _scrollController,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        itemCount: _students.length + (_loadingMore ? 1 : 0),
        itemBuilder: (context, index) {
          if (index >= _students.length) {
            return const Padding(
              padding: EdgeInsets.symmetric(vertical: 18),
              child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
            );
          }

          return _RosterStudentRow(student: _students[index]);
        },
      ),
    );
  }
}

class _RosterHeader extends StatelessWidget {
  const _RosterHeader({required this.controller});

  final ScannerController controller;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final scope = controller.participationScopeName ?? 'Active event scope';
    final rosterLabel = controller.rosterCount == 1
        ? '1 hydrated student'
        : '${controller.rosterCount} hydrated students';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: c.panel,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: c.panelBorder),
        boxShadow: [
          BoxShadow(
            color: c.softShadow,
            blurRadius: 18,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: c.brandWash,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(
              Icons.groups_2_rounded,
              color: c.isDark ? AppColors.usmGold : AppColors.brandGreen,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  rosterLabel,
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: c.onSurface,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  scope,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 12,
                    height: 1.35,
                    color: c.onSurfaceMuted,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RosterStudentRow extends StatelessWidget {
  const _RosterStudentRow({required this.student});

  final RosterStudent student;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final meta = [
      if ((student.programCode ?? '').trim().isNotEmpty) student.programCode!,
      if ((student.studentIdNumber ?? '').trim().isNotEmpty)
        student.studentIdNumber!,
    ];

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: c.panel,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: c.panelBorder),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: c.goldWash,
              borderRadius: BorderRadius.circular(13),
            ),
            child: Icon(
              Icons.person_outline_rounded,
              color: c.isDark ? AppColors.usmGold : AppColors.brandGreen,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  student.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    height: 1.25,
                    color: c.onSurface,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  meta.isEmpty ? 'No program listed' : meta.join(' - '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 12,
                    color: c.onSurfaceMuted,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RosterEmptyState extends StatelessWidget {
  const _RosterEmptyState({
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);

    return ListView(
      padding: const EdgeInsets.fromLTRB(32, 56, 32, 24),
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        Icon(
          icon,
          size: 48,
          color: c.isDark ? AppColors.usmGold : AppColors.brandGreen,
        ),
        const SizedBox(height: 16),
        Text(
          title,
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: c.onSurface,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          message,
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 13,
            height: 1.45,
            color: c.onSurfaceMuted,
          ),
        ),
        if (actionLabel != null) ...[
          const SizedBox(height: 20),
          Center(
            child: FilledButton.icon(
              onPressed: onAction,
              icon: const Icon(Icons.download_rounded),
              label: Text(actionLabel!),
            ),
          ),
        ],
      ],
    );
  }
}
