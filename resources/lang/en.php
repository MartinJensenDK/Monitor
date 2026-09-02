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
    'nav.locations' => 'Locations',
    'nav.settings' => 'Settings',
    'nav.activity' => 'Activity log',
    'nav.profile' => 'Your profile',
    'nav.sign_out' => 'Sign out',
    'nav.theme_dark' => 'Switch to dark',
    'nav.theme_light' => 'Switch to light',

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
    'action.add_location' => 'Add location',
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
    'dashboard.where' => 'Where',
    'dashboard.map_title' => 'Monitors on the map',
    'dashboard.map_hint' => 'Scroll to zoom, drag to move. A pin takes the colour of the worst thing at that location.',

    // Locations
    'location.list_title' => 'The places your monitors stand in',
    'location.name' => 'Name',
    'location.name_hint' => 'What people here call the place. It labels the pin on the map.',
    'location.address' => 'Address',
    'location.address_hint' => 'Optional, and free text: a street, a city, a rack. Whatever tells someone where to go.',
    'location.coordinates' => 'Coordinates',
    'location.latitude' => 'Latitude',
    'location.longitude' => 'Longitude',
    'location.where_title' => 'Where on Earth',
    'location.pick_hint' => 'Click the map to drop the pin, or type the coordinates. Scroll to zoom in, drag to move. Nothing is looked up anywhere: this site asks no one where your addresses are.',
    'location.whats_here' => 'What is watched here',
    'location.nothing_here' => 'Nothing yet. Pick this location on a monitor and it appears on the map.',
    'location.empty_title' => 'No locations yet',
    'location.empty_body' => 'A location puts a monitor on the map. Add the places your things actually stand in, then pick one when you create a monitor.',

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
    'monitor.location' => 'Location',
    'monitor.location_hint' => 'Optional. Pinning it puts it on the dashboard map; leaving it unpinned changes nothing about the checks.',
    'monitor.no_location' => 'Not tied to a place',
    'monitor.any_location' => 'Any location',
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

    // The type picker: what each kind of check watches, and what it takes for
    // that check to call the thing down. Same two columns as the table in the
    // README, because the question is the same one -- which of these nine is
    // the one I want?
    'monitor.about_http' => 'A page over HTTP',
    'monitor.about_keyword' => 'The wording on a page',
    'monitor.about_endpoint' => 'A JSON API, one call',
    'monitor.about_api' => 'A JSON API, several calls',
    'monitor.about_ping' => 'A host answering',
    'monitor.about_port' => 'A service listening',
    'monitor.about_ssl' => 'A certificate and its clock',
    'monitor.about_domain' => 'A registration',
    'monitor.about_dns' => 'A record',

    'monitor.down_http' => 'Down when the status code falls outside the expected range, a keyword is missing (or present), the TLS handshake fails, or it times out.',
    'monitor.down_keyword' => 'Down when the words you named are missing — or, the other way round, an error phrase has appeared. Several at once, matched on the visible text rather than the markup.',
    'monitor.down_endpoint' => 'Down when any of your assertions about the response body does not hold — queue.depth under 100, status equal to ok, items.0.state present.',
    'monitor.down_api' => 'Down when any step in the sequence fails. Sign in, capture the token, call the endpoint it unlocks — the failure names the step.',
    'monitor.down_ping' => 'Down when no ICMP echo comes back. Where the server may not send ICMP, it times a TCP connect instead and says so rather than pretending.',
    'monitor.down_port' => 'Down when nothing accepts the connection, or the greeting it sends is not the one you expected.',
    'monitor.down_ssl' => 'Down when it has expired, the chain is not trusted, it does not cover the host, the issuer changed, or it runs out sooner than you allow.',
    'monitor.down_domain' => 'Down when the registry has no record of it, it is on hold, it changed registrar or nameservers, or it expires sooner than you allow.',
    'monitor.down_dns' => 'Down when the name does not resolve, the record is gone, it no longer holds what you published, or two resolvers disagree about it.',
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
