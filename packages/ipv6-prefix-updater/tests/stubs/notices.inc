<?php

declare(strict_types=1);

/** @var int Controllable replacement for the time() value used by file_notice(). */
$noticeTestTime = 1_700_000_000;

/** @var array<int|string, array<string, string>> Simulated pfSense notice queue. */
$noticeTestNotices = [];

/** @var list<array<string, mixed>> Raw file_notice() calls made by a test. */
$noticeTestFileCalls = [];

/** @var list<int|string> Raw close_notice() calls made by a test. */
$noticeTestCloseCalls = [];

if (function_exists('file_notice') === false) {
    /**
     * Files one notice under the first available time-based queue key.
     *
     * Stored fields match pfSense CE 2.9.0, while the separate raw call list lets
     * tests inspect local-only delivery without adding it to the persisted entry.
     *
     * @param string $id        - Notice identifier.
     * @param string $notice    - Notice text.
     * @param string $category  - Notice category.
     * @param string $url       - Notice link.
     * @param int    $priority  - Notice priority.
     * @param bool   $localOnly - Whether remote channels are suppressed.
     *
     * @return void
     *
     * @since 2.1.2
     */
    function file_notice(
        string $id,
        string $notice,
        string $category = 'General',
        string $url = '',
        int $priority = 1,
        bool $localOnly = false,
    ): void {
        global $noticeTestFileCalls;
        global $noticeTestNotices;
        global $noticeTestTime;

        $noticeQueueIdentifier = $noticeTestTime;

        while (array_key_exists($noticeQueueIdentifier, $noticeTestNotices)) {
            $noticeQueueIdentifier++;
        }

        $noticeTestNotices[$noticeQueueIdentifier] = [
            'id' => htmlentities($id),
            'notice' => htmlentities($notice),
            'url' => htmlentities($url),
            'category' => htmlentities($category),
            'priority' => htmlentities((string) $priority),
        ];
        $noticeTestFileCalls[] = compact(
            'id',
            'notice',
            'category',
            'url',
            'priority',
            'localOnly',
        );
    }
}

if (function_exists('get_notices') === false) {
    /**
     * Returns the simulated notice queue or false when it is empty.
     *
     * pfSense uses false for both a missing notice file and an empty notice file,
     * so package lifecycle tests must not treat that result as a malformed queue.
     *
     * @return array<int|string, array<string, string>>|false
     *
     * @since 2.1.2
     */
    function get_notices(): array|false
    {
        global $noticeTestNotices;

        if (count($noticeTestNotices) === 0) {
            return false;
        }

        return $noticeTestNotices;
    }
}

if (function_exists('close_notice') === false) {
    /**
     * Closes at most one queued notice unless every notice was requested.
     *
     * A queue-key match wins. Only when no key matches does pfSense remove the
     * first entry whose stored identifier equals the requested identifier.
     *
     * @param int|string $id - Queue key, stored notice identifier, or all.
     *
     * @return void
     *
     * @since 2.1.2
     */
    function close_notice(int|string $id): void
    {
        global $noticeTestCloseCalls;
        global $noticeTestNotices;

        $noticeTestCloseCalls[] = $id;

        if ($id === 'all') {
            $noticeTestNotices = [];

            return;
        }

        if (array_key_exists($id, $noticeTestNotices)) {
            unset($noticeTestNotices[$id]);

            return;
        }

        foreach ($noticeTestNotices as $noticeQueueIdentifier => $notice) {
            if (($notice['id'] ?? null) !== (string) $id) {
                continue;
            }

            unset($noticeTestNotices[$noticeQueueIdentifier]);

            return;
        }
    }
}
