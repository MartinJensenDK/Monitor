<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The standing facts about a machine that are worth saying out loud.
 *
 * Not answers to something somebody did -- those are flashes, and they are
 * gone in three seconds. These are conditions: they are true until the machine
 * says otherwise, they are dismissed rather than waited out, and they are
 * dismissed against a value rather than for good.
 *
 * That last part is the whole design. A machine with nine updates waiting that
 * somebody has seen and sent away has nothing new to say until the number
 * moves; one that has just found forty more does. So every notice carries the
 * value it is currently true for, and an empty value means it is not true at
 * all -- which is also what takes it off the screen and forgets the dismissal,
 * so the next time it becomes true it is news again.
 *
 * Listed here rather than in the page because the page is not the only thing
 * that renders them: the live channel sends the same specs so they can keep
 * themselves right without a reload.
 */
final class DeviceNotices
{
    /**
     * Every notice this machine could show, active or not.
     *
     * The inactive ones are included on purpose. A page that only heard about
     * what is true now could never clear a dismissal for something that has
     * stopped being true, and the machine would go quiet about it the next
     * time round.
     *
     * @param array<string,mixed> $device
     * @return array<int,array{key:string,value:string,kind:string,icon:string,text:string}>
     */
    public static function forDevice(array $device): array
    {
        $uuid = (string) $device['uuid'];
        $pending = (int) ($device['updates_total'] ?? 0);
        $security = (int) ($device['updates_security'] ?? 0);
        $reboot = (int) ($device['reboot_required'] ?? 0) === 1;

        return [
            [
                'key' => 'monitor.updates.' . $uuid,
                'value' => $pending > 0 ? (string) $pending : '',
                'kind' => $security > 0 ? 'error' : 'warning',
                'icon' => $security > 0 ? 'shield' : 'download',
                'text' => $security > 0
                    ? t('device.updates_notice_security', ['count' => $pending, 'security' => $security])
                    : t('device.updates_notice', ['count' => $pending]),
            ],
            [
                // A restart is owed or it is not, so the value it is dismissed
                // against is simply that it is owed. Restart the machine and
                // the notice goes; let it fall due again and it is new.
                'key' => 'monitor.reboot.' . $uuid,
                'value' => $reboot ? '1' : '',
                'kind' => 'warning',
                'icon' => 'refresh',
                'text' => t('device.reboot_banner'),
            ],
        ];
    }
}
