<?php

namespace app\common\lib;

/**
 * 站点 Logo / 网站图标上传。
 *
 * 不依赖 fileinfo 扩展（很多虚拟主机没开）：按扩展名白名单 + 文件头魔数 + getimagesize() 三重校验，
 * 拒绝 SVG（可内嵌脚本）与夹带 PHP/HTML 的图片，文件随机重命名后存到网站根目录的 uploads/brand/，
 * 该目录自带 .htaccess / web.config 禁止脚本执行。
 */
class BrandUpload
{
    /** 每种用途允许的格式与大小上限（字节） */
    public static function rules()
    {
        return [
            'site_logo' => [['png', 'jpg', 'jpeg', 'gif', 'webp'], 1048576, '站点 Logo'],
            'site_logo_light' => [['png', 'jpg', 'jpeg', 'gif', 'webp'], 1048576, '深色背景 Logo'],
            'site_favicon' => [['ico', 'png'], 262144, '网站图标'],
        ];
    }

    /** 公共上传目录（网站根目录下 uploads/brand） */
    public static function dir()
    {
        return rtrim(WEB_ROOT, '/') . '/uploads/brand';
    }

    /**
     * 校验文件，返回 [扩展名, 错误信息]；通过时错误为空串
     *
     * @param string $tmp  临时文件路径
     * @param string $name 原文件名
     * @param int    $size 文件大小
     * @param string $key  用途（rules() 的键）
     */
    public static function check($tmp, $name, $size, $key)
    {
        $rules = self::rules();
        if (!isset($rules[$key])) {
            return ['', '未知的上传用途'];
        }
        list($exts, $max, $label) = $rules[$key];
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            return ['', $label . '只支持 ' . strtoupper(implode('/', $exts)) . ' 格式'];
        }
        if ($size <= 0 || !is_file($tmp)) {
            return ['', $label . '上传失败，请重新选择文件'];
        }
        if ($size > $max) {
            return ['', $label . '不能超过 ' . round($max / 1024) . ' KB'];
        }
        $head = (string)file_get_contents($tmp, false, null, 0, 16);
        $kind = self::sniff($head);
        if ($kind === '') {
            return ['', $label . '不是有效的图片文件'];
        }
        $same = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp', 'ico' => 'ico'];
        if ($same[$ext] !== $kind) {
            return ['', $label . '的扩展名与实际格式不符'];
        }
        // 防止「图片 + 脚本」拼接文件
        $all = (string)file_get_contents($tmp);
        if (preg_match('/<\?php|<\?=|<script|<html|<svg/i', $all)) {
            return ['', $label . '包含不允许的内容'];
        }
        if ($kind === 'ico') {
            $n = unpack('vcount', substr($head, 4, 2));
            if (empty($n['count']) || $n['count'] > 64) {
                return ['', $label . '不是有效的 ICO 文件'];
            }

            return [$ext, ''];
        }
        if ($kind === 'webp' && !defined('IMAGETYPE_WEBP')) {
            // PHP 7.0 的 getimagesize 不认识 WebP，只做魔数校验
            return [$ext, ''];
        }
        $info = @getimagesize($tmp);
        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return ['', $label . '无法识别为图片'];
        }
        if ($info[0] > 4000 || $info[1] > 4000) {
            return ['', $label . '尺寸过大（最大 4000×4000 像素）'];
        }

        return [$ext, ''];
    }

    /** 根据文件头判断真实格式 */
    public static function sniff($head)
    {
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpeg';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strncmp($head, "\x00\x00\x01\x00", 4) === 0) {
            return 'ico';
        }

        return '';
    }

    /**
     * 保存上传文件，返回 [相对路径, 错误信息]
     *
     * @param array  $file $_FILES 里的单个文件
     * @param string $key  用途
     */
    public static function save(array $file, $key)
    {
        $err = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['', ''];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['', $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? '文件超过服务器允许的大小' : '上传失败（错误码 ' . $err . '）'];
        }
        list($ext, $msg) = self::check($file['tmp_name'], $file['name'], (int)$file['size'], $key);
        if ($msg !== '') {
            return ['', $msg];
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['', '无法创建上传目录 uploads/brand，请检查网站根目录写入权限（755 即可，不需要 777）'];
        }
        $name = str_replace('_', '-', $key) . '-' . bin2hex(random_bytes(6)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $target = $dir . '/' . $name;
        $ok = is_uploaded_file($file['tmp_name']) ? @move_uploaded_file($file['tmp_name'], $target) : @rename($file['tmp_name'], $target);
        if (!$ok) {
            return ['', '保存文件失败，请检查 uploads/brand 目录写入权限'];
        }
        @chmod($target, 0644);

        return ['uploads/brand/' . $name, ''];
    }

    /** 删除旧文件（只删除 uploads/brand 下由本功能生成的文件） */
    public static function remove($relative)
    {
        $relative = (string)$relative;
        if (!preg_match('#^uploads/brand/(site-logo|site-logo-light|site-favicon)-[a-f0-9]{12}\.(png|jpg|gif|webp|ico)$#', $relative)) {
            return;
        }
        $path = rtrim(WEB_ROOT, '/') . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
