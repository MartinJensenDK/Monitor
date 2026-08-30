<?php

declare(strict_types=1);

/**
 * Interface text. Copy this file to a new locale code (da.php, de.php …),
 * translate the values, and pick it under Settings → Default language.
 * Missing keys fall back to English.
 */

return [
    // Navigation
    'nav.overview' => 'Overview',
    'nav.dashboard' => 'Dashboard',
    'nav.monitors' => 'Monitors',
    'nav.incidents' => 'Incidents',
    'nav.administration' => 'Administration',
    'nav.people' => 'People',
    'nav.groups' => 'Groups',
    'nav.settings' => 'Settings',
    'nav.activity' => 'Activity log',
    'nav.profile' => 'Your profile',
    'nav.sign_out' => 'Sign out',

    // Status
    'status.up' => 'up',
    'status.down' => 'down',
    'status.degraded' => 'degraded',
    'status.paused' => 'paused',
    'status.pending' => 'pending',
    'status.all_operational' => 'All systems operational',
    'status.monitors_down' => ':count monitors down',
    'status.monitor_down' => '1 monitor down',
    'status.degraded_count' => ':count degraded',
    'status.nothing_yet' => 'Waiting for the first check',

    // Roles
    'role.admin' => 'Administrator',
    'role.editor' => 'Editor',
    'role.viewer' => 'Viewer',
    'role.admin_hint' => 'Everything, including people, groups and settings.',
    'role.editor_hint' => 'Creates and edits monitors in the groups they belong to.',
    'role.viewer_hint' => 'Reads dashboards and incidents. Changes nothing.',

    // Shared actions
    'action.save' => 'Save changes',
    'action.cancel' => 'Cancel',
    'action.create' => 'Create',
    'action.delete' => 'Delete',
    'action.edit' => 'Edit',
    'action.pause' => 'Pause',
    'action.resume' => 'Resume',
    'action.check_now' => 'Check now',
    'action.acknowledge' => 'Acknowledge',
    'action.add_monitor' => 'Add monitor',
    'action.add_person' => 'Add person',
    'action.add_group' => 'Add group',
    'action.back' => 'Back',
    'action.filter' => 'Filter',
    'action.search' => 'Search',

    // Dashboard
    'dashboard.title' => 'Dashboard',
    'dashboard.live' => 'Live',
    'dashboard.uptime_24h' => 'Uptime, 24 hours',
    'dashboard.response' => 'Average response',
    'dashboard.open_incidents' => 'Open incidents',
    'dashboard.monitored' => 'Monitored',
    'dashboard.fleet_tape' => 'Every check across all your monitors, oldest on the left.',
    'dashboard.response_over_time' => 'Response time',
    'dashboard.recent_incidents' => 'Recent incidents',
    'dashboard.no_incidents' => 'No incidents recorded. That is the good outcome.',
    'dashboard.empty_title' => 'Nothing is being watched yet',
    'dashboard.empty_body' => 'Add your first monitor and the first check runs within a minute.',

    // Monitors
    'monitor.name' => 'Name',
    'monitor.type' => 'Type',
    'monitor.target' => 'URL',
    'monitor.status' => 'Status',
    'monitor.response' => 'Response',
    'monitor.uptime' => 'Uptime',
    'monitor.last_check' => 'Last check',
    'monitor.next_check' => 'Next check',
    'monitor.interval' => 'Check every',
    'monitor.timeout' => 'Timeout',
    'monitor.retries' => 'Retries before down',
    'monitor.degraded_ms' => 'Degraded above',
    'monitor.tags' => 'Tags',
    'monitor.enabled' => 'Checks are running',
    'monitor.shared_with' => 'Shared with',
    'monitor.recent_checks' => 'Recent checks',
    'monitor.certificate' => 'TLS certificate',
    'monitor.history' => 'Daily uptime, 30 days',
    'monitor.no_access' => 'No group has access yet',
    'monitor.type_http' => 'Website',
    'monitor.type_keyword' => 'Keyword',
    'monitor.type_endpoint' => 'Endpoint',
    'monitor.type_api' => 'API',
    'monitor.type_ping' => 'Ping',
    'monitor.type_port' => 'Port',
    'monitor.type_ssl' => 'SSL certificate',
    'monitor.type_domain' => 'Domain',
    'monitor.type_dns' => 'DNS',
    'monitor.expiry' => 'Expires in',
    'monitor.needs_migration' => 'Waiting on a database update',
    'monitor.coming_soon' => 'Arrives in the next release',

    // Incidents
    'incident.started' => 'Started',
    'incident.resolved' => 'Resolved',
    'incident.duration' => 'Duration',
    'incident.cause' => 'Cause',
    'incident.ongoing' => 'Ongoing',
    'incident.acknowledged_by' => 'Acknowledged by :name',
    'incident.none' => 'No incidents. Every monitor has answered every check.',

    // Access
    'access.none' => 'No access',
    'access.view' => 'Can view',
    'access.edit' => 'Can edit',
    'access.managed' => 'Managed in Microsoft Entra ID',

    // Time
    'time.just_now' => 'just now',
    'time.ago' => ':time ago',
    'time.never' => 'never',

    // Validation
    'validation.required' => ':field is required.',
    'validation.email' => ':field must be a valid email address.',
    'validation.url' => ':field must start with http:// or https:// and include a host name.',
    'validation.between' => ':field must be between :min and :max.',
    'validation.max' => ':field cannot be longer than :max characters.',
    'validation.invalid' => ':field is not one of the allowed values.',
    'validation.password' => 'The password must be at least 10 characters.',
    'validation.confirm' => 'The two passwords do not match.',
];
