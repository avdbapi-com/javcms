<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 刘志淳 <chun@engineer.com>
// +----------------------------------------------------------------------
namespace think\helper;

class Time
{
    /* *
 * Returns the timestamps for the start and end of today
 *
 * @return array */
    public static function today()
    {
        return [
            mktime(0, 0, 0, date('m'), date('d'), date('Y')),
            mktime(23, 59, 59, date('m'), date('d'), date('Y'))
        ];
    }

    /* *
 * Return the timestamps for the start and end of yesterday
 *
 * @return array */
    public static function yesterday()
    {
        $yesterday = date('d') - 1;
        return [
            mktime(0, 0, 0, date('m'), $yesterday, date('Y')),
            mktime(23, 59, 59, date('m'), $yesterday, date('Y'))
        ];
    }

    /* *
 * Returns the timestamps for the beginning and end of the week
 *
 * @return array */
    public static function week()
    {
        $timestamp = time();
        return [
            strtotime(date('Y-m-d', strtotime("+0 week Monday", $timestamp))),
            strtotime(date('Y-m-d', strtotime("+0 week Sunday", $timestamp))) + 24 * 3600 - 1
        ];
    }

    /* *
 * Returns the timestamps for the beginning and end of the previous week
 *
 * @return array */
    public static function lastWeek()
    {
        $timestamp = time();
        return [
            strtotime(date('Y-m-d', strtotime("last week Monday", $timestamp))),
            strtotime(date('Y-m-d', strtotime("last week Sunday", $timestamp))) + 24 * 3600 - 1
        ];
    }

    /* *
 * Returns the timestamps for the beginning and end of the month
 *
 * @return array */
    public static function month($everyDay = false)
    {
        return [
            mktime(0, 0, 0, date('m'), 1, date('Y')),
            mktime(23, 59, 59, date('m'), date('t'), date('Y'))
        ];
    }

    /* *
 * Returns the timestamps for the beginning and end of the previous month
 *
 * @return array */
    public static function lastMonth()
    {
        $begin = mktime(0, 0, 0, date('m') - 1, 1, date('Y'));
        $end = mktime(23, 59, 59, date('m') - 1, date('t', $begin), date('Y'));

        return [$begin, $end];
    }

    /* *
 * Returns the timestamps for the beginning and end of the year
 *
 * @return array */
    public static function year()
    {
        return [
            mktime(0, 0, 0, 1, 1, date('Y')),
            mktime(23, 59, 59, 12, 31, date('Y'))
        ];
    }

    /* *
 * Returns the timestamps of the beginning and end of the previous year
 *
 * @return array */
    public static function lastYear()
    {
        $year = date('Y') - 1;
        return [
            mktime(0, 0, 0, 1, 1, $year),
            mktime(23, 59, 59, 12, 31, $year)
        ];
    }

    public static function dayOf()
    {

    }

    /* *
 * Return a range from midnight the specified number of days ago to now or the end of yesterday
 *
 * @param int $day number of days
 * @param bool $now Whether the range ends now rather than at the end of yesterday
 * @return array */
    public static function dayToNow($day = 1, $now = true)
    {
        $end = time();
        if (!$now) {
            list($foo, $end) = self::yesterday();
        }

        return [
            mktime(0, 0, 0, date('m'), date('d') - $day, date('Y')),
            $end
        ];
    }

    /* *
 * Return the timestamp the specified number of days ago
 *
 * @param int $day
 * @return int */
    public static function daysAgo($day = 1)
    {
        $nowTime = time();
        return $nowTime - self::daysToSecond($day);
    }

    /* *
 * Return the timestamp the specified number of days from now
 *
 * @param int $day
 * @return int */
    public static function daysAfter($day = 1)
    {
        $nowTime = time();
        return $nowTime + self::daysToSecond($day);
    }

    /* *
 * Convert days to seconds
 *
 * @param int $day
 * @return int */
    public static function daysToSecond($day = 1)
    {
        return $day * 86400;
    }

    /* *
 * Convert weeks to seconds
 *
 * @param int $week
 * @return int */
    public static function weekToSecond($week = 1)
    {
        return self::daysToSecond() * 7 * $week;
    }

    private static function startTimeToEndTime()
    {

    }
}