<?php
/*
 * Backup Check - minimal 5-field cron evaluator.
 * Supports: *, numbers, a-b ranges, lists (a,b), steps (*\/n, a-b/n). Day-of-week 0 or 7 = Sunday.
 * Follows cron's rule: when both day-of-month and day-of-week are restricted, either may match.
 */
class BCCron
{
    private static function field($f, $min, $max)
    {
        $vals = [];
        foreach (explode(',', $f) as $part) {
            $step = 1;
            if (strpos($part, '/') !== false) {
                list($part, $s) = explode('/', $part, 2);
                $step = max(1, (int)$s);
            }
            if ($part === '*' || $part === '') {
                $a = $min; $b = $max;
            } elseif (strpos($part, '-') !== false) {
                list($a, $b) = array_map('intval', explode('-', $part, 2));
            } else {
                $a = (int)$part;
                $b = ($step > 1) ? $max : $a;
            }
            for ($v = $a; $v <= $b; $v += $step) {
                if ($v >= $min && $v <= $max) $vals[$v] = true;
            }
        }
        ksort($vals);
        return $vals;
    }

    public static function parse($expr)
    {
        $p = preg_split('/\s+/', trim((string)$expr));
        if (count($p) < 5) return null;
        foreach (array_slice($p, 0, 5) as $f) {
            if (!preg_match('#^[0-9*,/\-]+$#', $f)) return null;   // no names/macros
        }
        $dow = self::field($p[4], 0, 7);
        if (isset($dow[7])) $dow[0] = true;
        return [
            'min'     => self::field($p[0], 0, 59),
            'hour'    => self::field($p[1], 0, 23),
            'dom'     => self::field($p[2], 1, 31),
            'mon'     => self::field($p[3], 1, 12),
            'dow'     => $dow,
            'domStar' => $p[2] === '*',
            'dowStar' => $p[4] === '*',
        ];
    }

    private static function dayMatches($c, $ts)
    {
        if (!isset($c['mon'][(int)date('n', $ts)])) return false;
        $d1 = isset($c['dom'][(int)date('j', $ts)]);
        $d2 = isset($c['dow'][(int)date('w', $ts)]);
        if ($c['domStar'] && $c['dowStar']) return true;
        if ($c['domStar']) return $d2;
        if ($c['dowStar']) return $d1;
        return $d1 || $d2;
    }

    /** All fire times (unix ts) in [$from, $to], ascending, at most $limit. */
    public static function between($expr, $from, $to, $limit = 1000)
    {
        $c = self::parse($expr);
        if (!$c) return [];
        $out = [];
        $day = strtotime(date('Y-m-d 00:00:00', $from));
        while ($day <= $to && count($out) < $limit) {
            if (self::dayMatches($c, $day)) {
                $y = (int)date('Y', $day); $m = (int)date('n', $day); $d = (int)date('j', $day);
                foreach (array_keys($c['hour']) as $h) {
                    foreach (array_keys($c['min']) as $mi) {
                        $t = mktime($h, $mi, 0, $m, $d, $y);
                        if ($t >= $from && $t <= $to) {
                            $out[] = $t;
                            if (count($out) >= $limit) break 2;
                        }
                    }
                }
            }
            $day = strtotime('+1 day', $day);
        }
        return $out;
    }

    /** Next fire time strictly after $from, or null (looks ahead ~13 months). */
    public static function next($expr, $from)
    {
        $r = self::between($expr, $from + 1, $from + 400 * 86400, 1);
        return $r ? $r[0] : null;
    }

    /** Most recent fire time at or before $at, or null (looks back ~13 months). */
    public static function prev($expr, $at)
    {
        $r = self::between($expr, $at - 400 * 86400, $at);
        return $r ? end($r) : null;
    }

    /** Rough interval in seconds between fires (used for "is this stale?"). */
    public static function interval($expr, $at)
    {
        $r = self::between($expr, $at - 70 * 86400, $at);
        $n = count($r);
        if ($n >= 2) return (int)(($r[$n - 1] - $r[0]) / ($n - 1));
        return 31 * 86400;
    }

    /** Human description, e.g. "Daily · 01:59", "Weekly · Sun 01:30", "Monthly · 4th 02:20". */
    public static function describe($expr)
    {
        $p = preg_split('/\s+/', trim((string)$expr));
        if (count($p) < 5) return 'Unknown schedule';
        list($mi, $h, $dom, $mon, $dow) = $p;
        $time = (ctype_digit($mi) && ctype_digit($h)) ? sprintf('%02d:%02d', $h, $mi) : null;
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        if ($time && $dom === '*' && $mon === '*' && $dow === '*') return 'Daily · ' . $time;
        if ($time && $dom === '*' && $mon === '*' && ctype_digit($dow)) return 'Weekly · ' . $days[(int)$dow] . ' ' . $time;
        if ($time && ctype_digit($dom) && $mon === '*' && $dow === '*') return 'Monthly · ' . self::ordinal((int)$dom) . ' ' . $time;
        if ($time && preg_match('#^\*/(\d+)$#', $dom, $m) && $mon === '*' && $dow === '*') return 'Every ' . $m[1] . ' days · ' . $time;
        return 'Custom · ' . implode(' ', array_slice($p, 0, 5));
    }

    private static function ordinal($n)
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }
}
