<?php
/**
 * Tiny file-based cache. Suitable for the single-server IIS setup; swap for
 * Redis/APCu later without changing callers. PHP 7.4 compatible.
 *
 * Entries are JSON files named gp_<md5(key)>.json in one flat directory, so
 * there is no directory tree to keep in sync. Expiry is enforced on read and
 * stale files are swept opportunistically on write (see gc()); expired files
 * are otherwise removed the next time their key is requested.
 */

namespace Operators\Cache;

class FileCache
{
    /** How often a write may trigger an opportunistic sweep (seconds). */
    const GC_INTERVAL = 3600;

    /** Orphaned .tmp files older than this are removed by gc() (seconds). */
    const TMP_MAX_AGE = 3600;

    /** @var string */
    private $dir;

    /** @var int Read TTL. <= 0 means "bypass reads". */
    private $ttl;

    /** @var int TTL used when storing. */
    private $writeTtl;

    /**
     * @param string   $dir
     * @param int      $ttl      Read TTL in seconds; <= 0 bypasses reads.
     * @param int|null $writeTtl TTL used when storing. Defaults to $ttl. Pass a
     *                           positive value with $ttl = 0 to "refresh": skip
     *                           the cached copy but repopulate it for everyone.
     */
    public function __construct($dir, $ttl = 60, $writeTtl = null)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->ttl = (int) $ttl;
        $this->writeTtl = $writeTtl === null ? $this->ttl : (int) $writeTtl;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function isWritable()
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function get($key)
    {
        if ($this->ttl <= 0) {
            return null;
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) @file_get_contents($file), true);
        if (!is_array($payload) || !isset($payload['expires_at'])) {
            return null;
        }
        if ($payload['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return isset($payload['value']) ? $payload['value'] : null;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function set($key, $value)
    {
        if ($this->writeTtl <= 0 || !is_dir($this->dir) || !is_writable($this->dir)) {
            return;
        }

        $payload = json_encode([
            'expires_at' => time() + $this->writeTtl,
            'created_at' => time(),
            'value'      => $value,
        ]);

        $file = $this->path($key);
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @unlink($file);
                @rename($tmp, $file);
            }
            $this->maybeGc();
        }
    }

    /**
     * Remove expired entries plus abandoned temporary files. Safe to call at
     * any time; also runs automatically at most once per GC_INTERVAL on write.
     *
     * @param int $maxAge Keep entries this many seconds past their expiry.
     * @return int Number of files removed.
     */
    public function gc($maxAge = 0)
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $now = time();
        $maxAge = max(0, (int) $maxAge);
        $removed = 0;

        $files = @glob($this->dir . DIRECTORY_SEPARATOR . 'gp_*.json');
        foreach ($files ?: [] as $file) {
            $payload = json_decode((string) @file_get_contents($file), true);
            if (is_array($payload) && isset($payload['expires_at'])) {
                $expired = ((int) $payload['expires_at'] + $maxAge) < $now;
            } else {
                // Unreadable/corrupt entry: keep it one day (plus maxAge) as a
                // safety net, then drop it.
                $expired = ((int) @filemtime($file) + max($maxAge, 86400)) < $now;
            }
            if ($expired && @unlink($file)) {
                $removed++;
            }
        }

        $tmpFiles = @glob($this->dir . DIRECTORY_SEPARATOR . 'gp_*.tmp');
        foreach ($tmpFiles ?: [] as $tmp) {
            if ((int) @filemtime($tmp) < $now - self::TMP_MAX_AGE) {
                @unlink($tmp);
            }
        }

        return $removed;
    }

    /**
     * Delete every cache entry, regardless of expiry. Intended for deploys and
     * manual maintenance (tools/cache-gc.php --all).
     *
     * @return int Number of files removed.
     */
    public function flush()
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $removed = 0;
        $files = @glob($this->dir . DIRECTORY_SEPARATOR . 'gp_*');
        foreach ($files ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Run gc() at most once per GC_INTERVAL, using a marker file so separate
     * PHP processes share the same throttle.
     *
     * @return void
     */
    private function maybeGc()
    {
        $marker = $this->dir . DIRECTORY_SEPARATOR . 'gp_gc.stamp';
        if (is_file($marker) && ((int) @filemtime($marker) > time() - self::GC_INTERVAL)) {
            return;
        }
        if (!@touch($marker)) {
            return;
        }
        $this->gc();
    }

    private function path($key)
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'gp_' . md5($key) . '.json';
    }
}

