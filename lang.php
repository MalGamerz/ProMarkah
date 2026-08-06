<?php
// lang.php
session_start();

// Default language
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

$lang = $_SESSION['lang'];

// English & Malay translations
$translations = [
    'en' => [
        'dashboard' => 'Dashboard',
        'students' => 'Students',
        'schools' => 'Schools',
        'sessions' => 'Sessions',
        'levels' => 'Levels',
        'tests' => 'Tests',
        'criteria' => 'Criteria',
        'groups' => 'Groups',
        'manual_scoring' => 'Manual Scoring',
        'view_marks' => 'View Marks',
        'test_preview' => 'Test Preview',
        'logout' => 'Logout',
        'manual_marks' => 'Manual Marks Entry',
        'select_group' => 'Select group...',
        'select_student' => 'Select student...',
        'select_test' => 'Select test...',
        'select_criteria' => 'Select criteria...',
        'score_placeholder' => '0–10',
        'add_criteria' => '＋ Add Another Criteria',
        'save_marks' => '💾 Save Marks'
    ],
    'ms' => [
        'dashboard' => 'Papan Pemuka',
        'students' => 'Pelajar',
        'schools' => 'Sekolah',
        'sessions' => 'Sesi',
        'levels' => 'Tahap',
        'tests' => 'Ujian',
        'criteria' => 'Kriteria',
        'groups' => 'Kumpulan',
        'manual_scoring' => 'Markah Manual',
        'view_marks' => 'Lihat Markah',
        'test_preview' => 'Pratonton Ujian',
        'logout' => 'Log Keluar',
        'manual_marks' => 'Masukkan Markah Manual',
        'select_group' => 'Pilih kumpulan...',
        'select_student' => 'Pilih pelajar...',
        'select_test' => 'Pilih ujian...',
        'select_criteria' => 'Pilih kriteria...',
        'score_placeholder' => '0–10',
        'add_criteria' => '＋ Tambah Kriteria Lain',
        'save_marks' => '💾 Simpan Markah'
    ]
];

function t($key) {
    global $translations, $lang;
    return $translations[$lang][$key] ?? $key;
}
?>
