<?php

namespace App\Services;

/**
 * 三平台主键与 ID 形态归一化（《三平台对接方案 V2.0》§4.3、§9）
 *
 * 方案里有两处极易踩坑的形态问题，都在这里收口：
 *
 * 1. userId —— 对外是 usr_<id>，本平台内部用十进制 uid。
 *    入站两种都收，落库统一十进制；出站一律回 usr_<id>。
 *
 * 2. courseId —— 路径里必须是纯数字（传 course_2 会打到 404），
 *    但响应里要回填 course_<id>。绑定表存纯数字串，响应再补前缀。
 *
 * 容错方向是"两种都收、一种输出"，不是"猜"。猜会把错数据写进库，
 * 而容错只是省掉一次双方为格式争执的联调。
 */
class PeerIdentity
{
    /**
     * userId → 十进制 uid
     *
     * "usr_10000" / "10000" / " 10000 " → "10000"
     * 无法识别时原样返回（交由上游判空），不做兜底造号。
     *
     * @param string $raw
     * @return string
     */
    public static function uid(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, 'usr_') === 0) {
            $raw = substr($raw, 4);
        }
        return trim($raw);
    }

    /**
     * 十进制 uid → 对外 userId
     *
     * @param string $uid
     * @return string
     */
    public static function userId(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '') {
            return '';
        }
        return stripos($uid, 'usr_') === 0 ? $uid : 'usr_' . $uid;
    }

    /**
     * courseId → 纯数字串（绑定表与成绩表都用这个形态）
     *
     * "course_2" / "2" / 2 → "2"
     *
     * @param mixed $raw
     * @return string
     */
    public static function courseId($raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, 'course_') === 0) {
            $raw = substr($raw, 7);
        }
        return trim($raw);
    }

    /**
     * 纯数字串 → 对外 courseId
     *
     * @param mixed $raw
     * @return string
     */
    public static function courseRef($raw): string
    {
        $pure = self::courseId($raw);
        return $pure === '' ? '' : 'course_' . $pure;
    }

    /**
     * taskId 归一化：纯数字补 task_ 前缀
     *
     * 路径参数常被写成 1001，而事件与绑定里是 task_1001。
     * 统一补前缀可以让两种写法指向同一个任务，且不会产生两张绑定。
     *
     * @param mixed $raw
     * @return string
     */
    public static function taskId($raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, 'task_') === 0) {
            return $raw;
        }
        return ctype_digit($raw) ? 'task_' . $raw : $raw;
    }

    /**
     * examId 归一化：仅去空白，不改写形态
     *
     * 考试 ID 由考试平台定义（如 exam_2），本平台无权改写——
     * 补前缀或剥前缀都可能指向一个不存在的考试。
     *
     * @param mixed $raw
     * @return string
     */
    public static function examId($raw): string
    {
        return trim((string) $raw);
    }
}
