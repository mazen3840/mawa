<?php
class DB {
    private static $pdo = null;

    public static function conn() {
        if (self::$pdo) return self::$pdo;
        $cfg = require __DIR__ . '/config.php';
        $db = $cfg['db'];
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // نفس توقيت PHP (القاهرة) عشان called_at و CURDATE() و NOW() تطلع بالتوقيت الصح
        try { self::$pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
        return self::$pdo;
    }
    public static function q($sql, $p = []) { $st = self::conn()->prepare($sql); $st->execute($p); return $st; }
    public static function one($sql, $p = []) { return self::q($sql, $p)->fetch(); }
    public static function all($sql, $p = []) { return self::q($sql, $p)->fetchAll(); }
    public static function insert($t, $d) {
        $c = array_keys($d);
        $ph = array_map(fn($x) => ":$x", $c);
        self::q("INSERT INTO `$t` (`".implode('`,`', $c)."`) VALUES (".implode(',', $ph).")", $d);
        return self::conn()->lastInsertId();
    }
    public static function update($t, $d, $w, $wp = []) {
        $s = [];
        foreach ($d as $k => $v) $s[] = "`$k` = :$k";
        self::q("UPDATE `$t` SET ".implode(',', $s)." WHERE $w", array_merge($d, $wp));
    }
}