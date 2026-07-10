import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../config/app_colors.dart';
import '../../../../config/theme_provider.dart';
import '../../../scanner/presentation/scanner_controller.dart';
import '../../../scanner/presentation/scanner_home_screen.dart';
import '../../domain/scanner_assignment.dart';
import '../auth_controller.dart';

class AssignedEventsScreen extends StatefulWidget {
  const AssignedEventsScreen({
    super.key,
    required this.authController,
    required this.scannerController,
  });

  final AuthController authController;
  final ScannerController scannerController;

  @override
  State<AssignedEventsScreen> createState() => _AssignedEventsScreenState();
}

class _AssignedEventsScreenState extends State<AssignedEventsScreen>
    with SingleTickerProviderStateMixin {
  // --- Brand tokens (mode-invariant accents are in AppColors) ---------------

  late final AnimationController _entranceController;
  late final Animation<double> _headerOpacity;
  late final Animation<double> _listSlide;
  late final Animation<double> _listOpacity;

  @override
  void initState() {
    super.initState();
    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    );
    _headerOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.4, curve: Curves.easeOut),
      ),
    );
    _listSlide = Tween<double>(begin: 30.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.15, 0.7, curve: Curves.easeOutCubic),
      ),
    );
    _listOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.15, 0.6, curve: Curves.easeOut),
      ),
    );
    _entranceController.forward();
  }

  @override
  void dispose() {
    _entranceController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final c = AppColors.of(context);
    SystemChrome.setSystemUIOverlayStyle(
      SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: isDark ? Brightness.light : Brightness.dark,
      ),
    );

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: isDark ? Colors.white : AppColors.brandGreenDark,
        titleTextStyle: TextStyle(
          fontFamily: 'Inter',
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: isDark ? Colors.white : AppColors.brandGreenDark,
        ),
        title: Row(
          children: [
            Icon(
              Icons.event_note_rounded,
              size: 20,
              color: AppColors.usmGold.withValues(alpha: 0.80),
            ),
            const SizedBox(width: 10),
            const Text('Assigned Events'),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Refresh assignments',
            onPressed: widget.authController.busy
                ? null
                : () => widget.authController.refreshAssignments(),
            icon: const Icon(Icons.refresh_rounded),
          ),
          IconButton(
            tooltip: 'Sign out',
            onPressed: widget.authController.busy
                ? null
                : widget.authController.signOut,
            icon: const Icon(Icons.logout_rounded),
          ),
          _ThemeToggleButton(),
          const SizedBox(width: 4),
        ],
      ),
      body: Builder(
        builder: (context) {
          return Container(
            width: double.infinity,
            height: double.infinity,
            decoration: BoxDecoration(
              gradient: RadialGradient(
                center: const Alignment(0.0, -0.6),
                radius: 1.4,
                colors: [c.gradientStart, c.gradientMid, c.gradientEnd],
                stops: const [0.0, 0.45, 1.0],
              ),
            ),
            child: AnimatedBuilder(
              animation: _entranceController,
              builder: (context, _) => RefreshIndicator(
                onRefresh: widget.authController.refreshAssignments,
                color: AppColors.usmGold,
                child: ListView(
                  padding: EdgeInsets.fromLTRB(
                    16,
                    MediaQuery.of(context).padding.top + kToolbarHeight + 8,
                    16,
                    32,
                  ),
                  children: [
                    // Header
                    Opacity(
                      opacity: _headerOpacity.value,
                      child: _buildHeader(context),
                    ),
                    const SizedBox(height: 16),
                    // Content
                    Opacity(
                      opacity: _listOpacity.value,
                      child: Transform.translate(
                        offset: Offset(0, _listSlide.value),
                        child: _buildContent(context),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildHeader(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.authController.statusMessage != null)
          _GlassBanner(
            icon: Icons.info_outline_rounded,
            message: widget.authController.statusMessage!,
            color: AppColors.accentGreen,
          ),
        if (widget.authController.errorMessage != null)
          _GlassBanner(
            icon: Icons.wifi_off_rounded,
            message: widget.authController.errorMessage!,
            color: AppColors.errorRedLight,
            isError: true,
          ),
      ],
    );
  }

  Widget _buildContent(BuildContext context) {
    final activeSession = widget.scannerController.session;
    final assignmentsToShow = widget.authController.assignments.where((a) {
      if (activeSession == null) return true;
      if (a.id == activeSession.assignmentId) return false;
      if (a.eventId == activeSession.eventId &&
          a.eventParticipationScopeId ==
              activeSession.eventParticipationScopeId) {
        return false;
      }
      return true;
    }).toList();

    return Column(
      children: [
        if (widget.scannerController.hasSession) ...[
          _ActiveSessionCard(
            title:
                widget.scannerController.eventTitle ?? 'Active scanner event',
            onOpen: () => _openScanner(context),
          ),
          const SizedBox(height: 16),
        ],
        if (assignmentsToShow.isEmpty && !widget.scannerController.hasSession)
          const _EmptyAssignmentsState()
        else if (assignmentsToShow.isNotEmpty)
          ...assignmentsToShow.map(
            (assignment) => Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: _AssignmentCard(
                assignment: assignment,
                busy: widget.authController.busy,
                onActivate: () async {
                  final activated = await widget.authController
                      .activateAssignment(assignment, widget.scannerController);
                  if (activated && context.mounted) {
                    _openScanner(context);
                  }
                },
              ),
            ),
          ),
      ],
    );
  }

  void _openScanner(BuildContext context) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ScannerHomeScreen(controller: widget.scannerController),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Active Session Card
// ═══════════════════════════════════════════════════════════════════════════════

class _ActiveSessionCard extends StatelessWidget {
  const _ActiveSessionCard({required this.title, required this.onOpen});

  final String title;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.brandGreen, const Color(0xFF1E6B54)],
        ),
        border: Border.all(
          color: AppColors.usmGold.withValues(alpha: 0.25),
          width: 1,
        ),
        boxShadow: [
          BoxShadow(
            color: AppColors.brandGreen.withValues(alpha: 0.40),
            blurRadius: 20,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.usmGold,
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.usmGold.withValues(alpha: 0.5),
                      blurRadius: 6,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Text(
                'ACTIVE SESSION',
                style: TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.usmGold,
                  letterSpacing: 1.2,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            title,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontFamily: 'Inter',
              fontSize: 17,
              fontWeight: FontWeight.w700,
              color: Colors.white,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Scanner session is active on this device.',
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 12,
              color: Colors.white.withValues(alpha: 0.60),
            ),
          ),
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: onOpen,
              icon: const Icon(Icons.qr_code_scanner_rounded, size: 18),
              label: const Text('Open scanner'),
              style: FilledButton.styleFrom(
                backgroundColor: Colors.white.withValues(alpha: 0.15),
                foregroundColor: Colors.white,
                minimumSize: const Size(0, 46),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: BorderSide(color: Colors.white.withValues(alpha: 0.20)),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Assignment Card (glassmorphic)
// ═══════════════════════════════════════════════════════════════════════════════

class _AssignmentCard extends StatelessWidget {
  const _AssignmentCard({
    required this.assignment,
    required this.busy,
    required this.onActivate,
  });

  final ScannerAssignment assignment;
  final bool busy;
  final VoidCallback onActivate;

  @override
  Widget build(BuildContext context) {
    final canActivate =
        assignment.activationAllowed &&
        assignment.isActive &&
        assignment.activationState != 'blocked_active_session';
    final isResume = assignment.activationState == 'resume';
    final isBlockedByActiveSession =
        assignment.activationState == 'blocked_active_session';
    final c = AppColors.of(context);

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: c.isDark ? c.panel : null,
        gradient: c.isDark
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  c.brandWash,
                  Colors.white,
                  canActivate
                      ? c.goldWash.withValues(alpha: 0.58)
                      : c.blueWash.withValues(alpha: 0.52),
                ],
                stops: const [0.0, 0.52, 1.0],
              ),
        border: Border.all(
          color: c.isDark
              ? c.panelBorder
              : (canActivate
                    ? AppColors.usmGold.withValues(alpha: 0.36)
                    : AppColors.accentBlue.withValues(alpha: 0.22)),
          width: 1,
        ),
        boxShadow: [
          BoxShadow(
            color: c.softShadow,
            blurRadius: 16,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (!c.isDark) ...[
            Container(
              height: 4,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: LinearGradient(
                  colors: [
                    AppColors.brandGreen,
                    AppColors.usmGold,
                    canActivate ? AppColors.accentGreen : AppColors.accentBlue,
                  ],
                ),
              ),
            ),
            const SizedBox(height: 14),
          ],
          _AssignmentHeader(
            assignment: assignment,
            statusLabel: _labelForStatus(assignment),
            canActivate: canActivate,
          ),
          const SizedBox(height: 12),
          // Divider
          Container(
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [Colors.transparent, c.divider, Colors.transparent],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            assignment.label == null || assignment.label!.isEmpty
                ? 'Event scanner'
                : assignment.label!,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 13,
              color: c.onSurfaceMuted,
            ),
          ),
          if (assignment.participationScopeName != null) ...[
            const SizedBox(height: 4),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  Icons.groups_rounded,
                  size: 14,
                  color: AppColors.usmGold.withValues(alpha: 0.70),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    'Scope: ${assignment.participationScopeName}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontFamily: 'Inter',
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                      color: AppColors.usmGold.withValues(alpha: 0.70),
                    ),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 4),
          Text(
            _helperText(assignment),
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              color: c.onSurfaceMuted.withValues(alpha: c.isDark ? 0.65 : 0.9),
              height: 1.4,
            ),
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: canActivate && !busy ? onActivate : null,
              icon: Icon(
                canActivate && !isResume
                    ? Icons.play_arrow_rounded
                    : isResume
                    ? Icons.replay_rounded
                    : Icons.lock_outline_rounded,
                size: 18,
              ),
              label: Text(
                canActivate
                    ? (isResume ? 'Resume scanner' : 'Activate event')
                    : (isBlockedByActiveSession
                          ? 'Admin revoke needed'
                          : 'Activation blocked'),
              ),
              style: FilledButton.styleFrom(minimumSize: const Size(0, 48)),
            ),
          ),
        ],
      ),
    );
  }

  String _labelForStatus(ScannerAssignment assignment) {
    if (assignment.activationState == 'blocked_active_session') return 'in use';
    if (assignment.activationState == 'resume') return 'resume';
    if (!assignment.isActive) return assignment.status;
    if (!assignment.activationAllowed) return 'locked';
    return assignment.eventPhase;
  }

  String _helperText(ScannerAssignment assignment) {
    if (assignment.activationState == 'blocked_active_session') {
      return assignment.activationMessage ??
          'This scanner assignment is already active on another device. Ask an admin to revoke the old scanner session before activating this device.';
    }
    if (assignment.activationState == 'resume') {
      return 'Scanner access is already active for this assignment. Resume this device-bound scanner session.';
    }
    if (assignment.status == 'revoked') {
      return 'Access was revoked by an admin. Pending scans remain on this device until synced or reviewed.';
    }
    if (assignment.status == 'expired') {
      return 'This assignment expired. Ask an admin to assign a new scanner session.';
    }
    if (!assignment.activationAllowed) {
      return 'The event is not currently available for scanner activation.';
    }
    return 'Login verified. Activation creates a device-bound scanner session for this event.';
  }
}

class _AssignmentHeader extends StatelessWidget {
  const _AssignmentHeader({
    required this.assignment,
    required this.statusLabel,
    required this.canActivate,
  });

  final ScannerAssignment assignment;
  final String statusLabel;
  final bool canActivate;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final titleSection = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          assignment.eventTitle,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 16,
            fontWeight: FontWeight.w700,
            color: c.onSurface,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          assignment.organizationAcronym ??
              assignment.organizationName ??
              'USM event',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 12,
            color: c.onSurfaceMuted,
          ),
        ),
      ],
    );

    final statusBadge = _StatusChip(label: statusLabel, active: canActivate);

    return LayoutBuilder(
      builder: (context, constraints) {
        final isNarrow = constraints.maxWidth < 270;

        if (isNarrow) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              titleSection,
              const SizedBox(height: 8),
              Align(alignment: Alignment.centerLeft, child: statusBadge),
            ],
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: titleSection),
            const SizedBox(width: 12),
            statusBadge,
          ],
        );
      },
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Status Chip
// ═══════════════════════════════════════════════════════════════════════════════

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.label, required this.active});

  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) {
    final color = active
        ? AppColors.accentGreen
        : AppColors.of(context).onSurfaceMuted;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        color: color.withValues(alpha: 0.12),
        border: Border.all(color: color.withValues(alpha: 0.30)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            active
                ? Icons.check_circle_outline_rounded
                : Icons.lock_outline_rounded,
            size: 13,
            color: color,
          ),
          const SizedBox(width: 5),
          Text(
            label,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Empty State
// ═══════════════════════════════════════════════════════════════════════════════

class _EmptyAssignmentsState extends StatelessWidget {
  const _EmptyAssignmentsState();

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    return Container(
      padding: const EdgeInsets.all(28),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: c.panel,
        border: Border.all(color: c.panelBorder),
      ),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: c.quietIconSurface,
            ),
            child: Icon(
              Icons.assignment_ind_outlined,
              size: 32,
              color: AppColors.usmGold.withValues(alpha: 0.60),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'No scanner assignments',
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: c.onSurface,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'You are signed in, but no admin has assigned this account as a scanner for an active event.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 13,
              color: c.onSurfaceMuted,
              height: 1.5,
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Glass Banner (status / error)
// ═══════════════════════════════════════════════════════════════════════════════

class _GlassBanner extends StatelessWidget {
  const _GlassBanner({
    required this.icon,
    required this.message,
    required this.color,
    this.isError = false,
  });

  final IconData icon;
  final String message;
  final Color color;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          color: color.withValues(alpha: 0.10),
          border: Border.all(color: color.withValues(alpha: 0.25)),
        ),
        child: Row(
          children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                message,
                style: TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 13,
                  color: isError ? color : c.onSurface,
                  height: 1.4,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Theme Toggle
// ═══════════════════════════════════════════════════════════════════════════════

class _ThemeToggleButton extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return IconButton(
      tooltip: isDark ? 'Switch to light mode' : 'Switch to dark mode',
      onPressed: () => ThemeProvider.instance.toggleTheme(),
      icon: AnimatedSwitcher(
        duration: const Duration(milliseconds: 350),
        transitionBuilder: (child, animation) {
          return RotationTransition(
            turns: Tween(begin: 0.75, end: 1.0).animate(animation),
            child: FadeTransition(opacity: animation, child: child),
          );
        },
        child: Icon(
          isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded,
          key: ValueKey(isDark),
          color: AppColors.usmGold,
          size: 22,
        ),
      ),
    );
  }
}
