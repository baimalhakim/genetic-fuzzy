<?php
session_start();
include '../../config/db.php';
require '../../job-board/lib/sesi_login.php';
require '../../job-board/lib/header-hc.php';
require '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Fungsi Fuzzifikasi
function fuzzification($value, $ranges) {
    list($low, $medium, $high) = $ranges; // Mendeklarasikan rentang nilai untuk low, medium, dan high

    // Validasi jika nilai berada di luar rentang
    if ($value < $low) $value = $low; // Jika nilai lebih kecil dari batas low, disesuaikan ke batas low
    if ($value > $high) $value = $high; // Jika nilai lebih besar dari batas high, disesuaikan ke batas high

    if ($value <= $low) {
        return ['low' => 1, 'medium' => 0, 'high' => 0]; // Jika nilai di bawah atau sama dengan low
    } elseif ($value > $low && $value <= $medium) {
        return [
            'low' => ($medium - $value) / ($medium - $low), // Perhitungan derajat keanggotaan low
            'medium' => ($value - $low) / ($medium - $low), // Perhitungan derajat keanggotaan medium
            'high' => 0
        ];
    } elseif ($value > $medium && $value <= $high) {
        return [
            'low' => 0,
            'medium' => ($high - $value) / ($high - $medium), // Perhitungan derajat keanggotaan medium
            'high' => ($value - $medium) / ($high - $medium) // Perhitungan derajat keanggotaan high
        ];
    } else {
        return ['low' => 0, 'medium' => 0, 'high' => 1]; // Jika nilai di atas atau sama dengan high
    }
}

// Fungsi Defuzzifikasi
function defuzzification($fuzzy_rules) {
    $total_weight = $fuzzy_rules['low'] + $fuzzy_rules['medium'] + $fuzzy_rules['high']; // Menghitung total bobot dari semua aturan fuzzy
    if ($total_weight == 0) return 0; // Jika total bobot nol, kembalikan nilai nol

    // Menghitung nilai defuzzifikasi berdasarkan bobot dan nilai
    return (
        ($fuzzy_rules['low'] * 0.2) +  // Kontribusi dari low
        ($fuzzy_rules['medium'] * 0.5) + // Kontribusi dari medium
        ($fuzzy_rules['high'] * 1.0) // Kontribusi dari high
    ) / $total_weight;
}

// Fuzzy Logic
function fuzzyLogic($score_test, $soft_skills, $score_interviews) {

    // Dokumentasi aturan fuzzy:
    // Aturan Fuzzy yang digunakan:
    // 1. IF Kompetensi = Tinggi AND Soft Skills = Tinggi AND Wawancara = Tinggi THEN Skor Seleksi = Tinggi
    // 2. IF Kompetensi = Rendah OR Wawancara = Rendah THEN Skor Seleksi = Rendah
    // 3. IF Kompetensi = Medium AND Soft Skills = Medium AND Wawancara = Medium THEN Skor Seleksi = Medium
    // 4. Kombinasi aturan lainnya diolah menggunakan max() dan min()

    // Lakukan fuzzifikasi untuk setiap input
    
    // Fuzzifikasi nilai/skor kompetensi, soft skills, dan wawancara
    $test_fuzz = fuzzification($score_test, [5, 50, 100]); // Fuzzifikasi nilai/skor kompetensi
    $skills_fuzz = fuzzification($soft_skills, [20, 60, 100]); // Fuzzifikasi nilai/skor soft skills
    $interviews_fuzz = fuzzification($score_interviews, [25, 50, 100]); // Fuzzifikasi nilai/skor wawancara

    if ($value < 0 || $value > 100) {
        throw new Exception("Invalid value for fuzzification: $value");
    }
    
    // Kombinasi aturan fuzzy
    $rules = [
        'low' => max(
            min($test_fuzz['low'], $skills_fuzz['low'], $interviews_fuzz['low']),
            min($test_fuzz['low'], $skills_fuzz['medium'], $interviews_fuzz['low']),
            min($test_fuzz['low'], $skills_fuzz['low'], $interviews_fuzz['medium']),
            min($test_fuzz['medium'], $skills_fuzz['low'], $interviews_fuzz['low'])
        ),
        'medium' => max(
            min($test_fuzz['medium'], $skills_fuzz['medium'], $interviews_fuzz['medium']),
            min($test_fuzz['low'], $skills_fuzz['high'], $interviews_fuzz['medium']),
            min($test_fuzz['high'], $skills_fuzz['low'], $interviews_fuzz['medium']),
            min($test_fuzz['medium'], $skills_fuzz['medium'], $interviews_fuzz['low']),
            min($test_fuzz['medium'], $skills_fuzz['low'], $interviews_fuzz['high'])
        ),
        'high' => max(
            min($test_fuzz['high'], $skills_fuzz['high'], $interviews_fuzz['high']),
            min($test_fuzz['medium'], $skills_fuzz['high'], $interviews_fuzz['high']),
            min($test_fuzz['high'], $skills_fuzz['medium'], $interviews_fuzz['high']),
            min($test_fuzz['high'], $skills_fuzz['high'], $interviews_fuzz['medium'])
        ),
    ];
    
    
    // Defuzzifikasi hasil aturan fuzzy
    $final_score = defuzzification($rules);

    // Debugging
    error_log("Fuzzy Logic Debug: " . json_encode([
        'test_fuzz' => $test_fuzz,
        'skills_fuzz' => $skills_fuzz,
        'interviews_fuzz' => $interviews_fuzz,
        'rules' => $rules,
        'final_score' => $final_score,
    ]));

    // Mengembalikan skor akhir dan detail perhitungan
    return [
        'final_score' => $final_score,
        'details' => [
            'test' => $test_fuzz,
            'skills' => $skills_fuzz,
            'interviews' => $interviews_fuzz,
            'rules' => $rules
        ]
    ];
}

$score_range = isset($_GET['score_range']) ? $_GET['score_range'] : 'all'; // Default: Semua rentang
$rank_limit = isset($_GET['rank_limit']) ? intval($_GET['rank_limit']) : 10; // Default: 10


// Algoritma Genetika
function geneticAlgorithm($job_id, $conn, $rank_limit, $score_range, $generations = 10, $mutation_rate = 0.1) {
    // Inisialisasi populasi dengan data kandidat dari database
    $stmt = $conn->prepare("
        SELECT 
            r.id, 
            r.applicant_id, 
            a.full_name, 
            r.score_test, 
            r.soft_skills, 
            r.score_interviews 
        FROM rankings r
        JOIN applicants a ON r.applicant_id = a.id
        WHERE r.job_id = ?
    ");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $population = [];
    while ($row = $result->fetch_assoc()) {
        $fuzzy_result = fuzzyLogic(
            $row['score_test'],
            $row['soft_skills'],
            $row['score_interviews']
        );
        $row['final_score'] = $fuzzy_result['final_score']; // Menyimpan skor akhir
        $row['details'] = $fuzzy_result['details']; // Menyimpan detail hasil fuzzy
        $row['total_score'] = $row['score_test'] + $row['soft_skills'] + $row['score_interviews']; // Menghitung skor total

    $population[] = $row; // Menambahkan kandidat ke populasi
    }

    // Melakukan iterasi generasi
    for ($gen = 0; $gen < $generations; $gen++) {
        usort($population, function ($a, $b) {
    if ($b['final_score'] != $a['final_score']) {
        return $b['final_score'] <=> $a['final_score']; // Mengurutkan berdasarkan final_score
    }

    if ($b['total_score'] != $a['total_score']) {
        return $b['total_score'] <=> $a['total_score']; // Mengurutkan jika final_score sama
    }

    // Jika total_score sama, urutkan berdasarkan skor wawancara
    if ($b['score_interviews'] != $a['score_interviews']) {
        return $b['score_interviews'] <=> $a['score_interviews'];
    }

    // Jika skor wawancara sama, urutkan berdasarkan soft_skills
    if ($b['soft_skills'] != $a['soft_skills']) {
        return $b['soft_skills'] <=> $a['soft_skills'];
    }

    // Jika soft_skills sama, urutkan berdasarkan score_test
    return $b['score_test'] <=> $a['score_test'];
});
      
$selected = array_slice($population, 0, $rank_limit); // Memilih individu terbaik

// Crossover
$new_population = [];
for ($i = 0; $i < count($selected); $i += 2) {
    if ($i + 1 < count($selected)) {
        $parent1 = $selected[$i];
        $parent2 = $selected[$i + 1];

        // Kombinasi skor dari kedua parent
        $child1 = [
            'score_test' => ($parent1['score_test'] + $parent2['score_test']) / 2,
            'soft_skills' => ($parent1['soft_skills'] + $parent2['soft_skills']) / 2,
            'score_interviews' => ($parent1['score_interviews'] + $parent2['score_interviews']) / 2,
        ];
        $child2 = [
            'score_test' => $parent1['score_test'],
            'soft_skills' => $parent2['soft_skills'],
            'score_interviews' => $parent1['score_interviews'],
        ];

        // Evaluasi anak-anak menggunakan fuzzy
        $child1['final_score'] = fuzzyLogic(
            $child1['score_test'], 
            $child1['soft_skills'], 
            $child1['score_interviews']
        )['final_score'];
            $child2['final_score'] = fuzzyLogic(
            $child2['score_test'], 
            $child2['soft_skills'], 
            $child2['score_interviews']
        )['final_score'];

        $new_population[] = $child1;
        $new_population[] = $child2;
    }
}

// Mutasi
foreach ($new_population as &$individual) {
    if (rand(0, 100) / 100 < $mutation_rate) {
        $individual['score_test'] = min(100, max(0, $individual['score_test'] + rand(-5, 5)));
        $individual['soft_skills'] = min(100, max(0, $individual['soft_skills'] + rand(-5, 5)));
        $individual['score_interviews'] = min(100, max(0, $individual['score_interviews'] + rand(-5, 5)));

        $individual['final_score'] = fuzzyLogic(
        $individual['score_test'], 
        $individual['soft_skills'], 
        $individual['score_interviews']
        )['final_score'];
    }
}

    $population = array_merge($selected, $new_population); // Menggabungkan populasi lama dengan generasi baru

    $population = array_slice($population, 0, $rank_limit); // Membatasi populasi berdasarkan kapasitas maksimal
}

// Mengurutkan populasi terakhir
usort($population, function ($a, $b) {
    // Urutkan berdasarkan final_score terlebih dahulu
    if ($b['final_score'] != $a['final_score']) {
        return $b['final_score'] <=> $a['final_score'];
    }
    
    // Jika final_score sama, urutkan berdasarkan total_score
    if ($b['total_score'] != $a['total_score']) {
        return $b['total_score'] <=> $a['total_score'];
    }
    
    // Jika total_score sama, urutkan berdasarkan skor wawancara
    if ($b['score_interviews'] != $a['score_interviews']) {
        return $b['score_interviews'] <=> $a['score_interviews'];
    }
    
    // Jika skor wawancara sama, urutkan berdasarkan soft_skills
    if ($b['soft_skills'] != $a['soft_skills']) {
        return $b['soft_skills'] <=> $a['soft_skills'];
    }
    
    // Jika soft_skills sama, urutkan berdasarkan score_test
    return $b['score_test'] <=> $a['score_test'];
});
    
$rank = 1;
foreach ($population as &$individual) {
    $individual['rank'] = $rank++;
    $individual['status'] = $individual['final_score'] > 0.50 ? 'Lulus' : 'Tidak Lulus';
}

return $population;
}

// Pilih Lowongan
$selected_job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicant_id = $_POST['applicant_id'] ?? null;
    $applicant_fullname = $_POST['full_name'] ?? null;
    $status = null;

    if (isset($_POST['Lulus'])) {
        $status = 'Lulus';
    } elseif (isset($_POST['Ditolak'])) {
        $status = 'Ditolak';
    }

    if ($applicant_id && $status) {
        // Perbarui status di tabel applications
        $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE applicant_id = ?");
        $stmt->bind_param("si", $status, $applicant_id);

        if ($stmt->execute()) {
            // Jika status berubah ke "Lulus", ambil email dari tabel users_job
            if ($status === 'Lulus') {
                // Ambil nama pelamar
                $stmt_pelamar = $conn->prepare("SELECT full_name FROM applicants WHERE id = ?");
                $stmt_pelamar->bind_param("i", $applicant_id);
                $stmt_pelamar->execute();
                $stmt_pelamar->bind_result($nama_pelamar);
                $stmt_pelamar->fetch();
                $stmt_pelamar->close();

                // Pilih Lowongan
if ($selected_job_id) {
    // Query untuk mengambil job_title berdasarkan selected_job_id
    $stmt_job = $conn->prepare("SELECT job_title FROM jobs WHERE id = ?");
    $stmt_job->bind_param("i", $selected_job_id);
    $stmt_job->execute();
    $stmt_job->bind_result($job_title);
    $stmt_job->fetch();
    $stmt_job->close();
} else {
    $job_title = 'Tidak Diketahui'; // Default jika selected_job_id tidak ditemukan
}

// Query untuk mengambil email berdasarkan applicant_id
             

$email_stmt = $conn->prepare("
    SELECT uj.email 
    FROM users_job uj
    JOIN applicants a ON uj.id = a.user_id
    WHERE a.id = ?
");
$email_stmt->bind_param("i", $applicant_id);
$email_stmt->execute();
$email_stmt->bind_result($email);
$email_stmt->fetch();
$email_stmt->close();

if ($email) {
    // Kirim email menggunakan PHPMailer
    $mail = new PHPMailer(true);

                    try {
                        // Konfigurasi SMTP
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'digitaltransformation.gobel@gmail.com';
                        $mail->Password = 'qbewqumakpyzcazo';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;
                        
                            // Pengirim dan penerima
                        $mail->setFrom('digitaltransformation.gobel@gmail.com', 'Human Capital PT Gobel International');
                        $mail->addAddress($email); // Email penerima dari tabel users_job
                        $mail->isHTML(true);
                        $mail->Subject = 'Status Pelamaran - PT Gobel International';
                        $mail->Body = "
                                <p>Yth. $nama_pelamar,<br>
                                Dengan hormat,</p>
                                <p>Kami dari PT Gobel International ingin mengucapkan selamat kepada Anda atas keberhasilan Anda melewati tahapan seleksi pada posisi <b>$job_title</b>,
                                Berdasarkan hasil seleksi yang telah dilakukan, kami sangat mengapresiasi kompetensi dan potensi yang Anda miliki.</p>
                                
                                <p>Langkah selanjutnya, kami akan menghubungi Anda untuk memberikan informasi terkait proses berikutnya. 
                                Mohon untuk tetap memeriksa email atau nomor telepon yang Anda gunakan untuk melamar, agar komunikasi dapat berjalan 
                                dengan lancar.</p>

                                <pre></pre>
                                <p>
                                Hormat kami,<br>
                                Tubagus Arief<br>
                                Director Human Capital PT Gobel International
                                </p>
                            ";
                        // Kirim email
                        $mail->send();

                        $_SESSION['alert'] = array(
                            'status' => 'success',
                            'title' => 'Sukses',
                            'text' => "Status berhasil diperbarui menjadi $status untuk ID pelamar $applicant_id dan email telah dikirim ke $email."
                        );
                    } catch (Exception $e) {
                        $_SESSION['alert'] = array(
                            'status' => 'warning',
                            'title' => 'Sukses dengan Peringatan',
                            'text' => "Status berhasil diperbarui menjadi $status untuk ID pelamar $applicant_id, tetapi email gagal dikirim. Error: {$mail->ErrorInfo}"
                        );
                    }
                } else {
                    $_SESSION['alert'] = array(
                        'status' => 'warning',
                        'title' => 'Sukses dengan Peringatan',
                        'text' => "Status berhasil diperbarui menjadi $status untuk ID pelamar $applicant_id, tetapi email tidak ditemukan."
                    );
                }
            } else {
                $_SESSION['alert'] = array(
                    'status' => 'success',
                    'title' => 'Sukses',
                    'text' => "Status berhasil diperbarui menjadi $status untuk ID pelamar $applicant_id."
                );
            }
        } else {
            $_SESSION['alert'] = array(
                'status' => 'error',
                'title' => 'Gagal',
                'text' => 'Gagal memperbarui status. Silakan coba lagi.'
            );
        }
        $stmt->close();
    } else {
        $_SESSION['alert'] = array(
            'status' => 'error',
            'title' => 'Gagal',
            'text' => 'Data tidak lengkap untuk memperbarui status.'
        );
    }
}

?>

<div class="wt-bnr-inr overlay-wraper bg-center" style="background-image:url(https://thewebmax.org/jobzilla/images/banner/1.jpg);">
    <div class="overlay-main site-bg-white opacity-01"></div>
    <div class="container">
        <div class="wt-bnr-inr-entry">
            <div class="banner-title-outer">
                <div class="banner-title-name">
                    <h2 class="wt-title">Kelola Hasil</h2>
                </div>
            </div>
            <div>
                <ul class="wt-breadcrumb breadcrumb-style-2">
                    <li><a href="/job-board/">Dasbor</a></li>
                    <li>Kelola Hasil</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="section-full p-t120  p-b90 site-bg-white" style="transform: none;">
    <div class="container">
        <div class="twm-right-section-panel candidate-save-job site-bg-gray">
            <div class="twm-candidates-grid-wrap">
                <div class="col-lg-12 col-md-6">
                    <div class="twm-candidates-grid-style1 mb-5">
                        <div class="twm-bnr-search-bar">
                        <form method="GET" action="">
                                <div class="row">
                                    <div class="form-group col-xl-4 col-lg-6 col-md-6">
                                        <label>Lowongan</label>
                                        <div class="dropdown bootstrap-select wt-search-bar-select dropup">
                                            <select class="wt-search-bar-select selectpicker" data-live-search="true" name="job_id" id="job_id">
                                                <option value="">Pilih Lowongan</option>
                                                <?php
                                                    $jobs = $conn->query("SELECT id, job_title FROM jobs ORDER BY job_title ASC");
                                                    while ($job = $jobs->fetch_assoc()) {
                                                        $selected = ($job['id'] == $selected_job_id) ? 'selected' : '';
                                                        echo "<option value='{$job['id']}' $selected>{$job['job_title']}</option>";
                                                        }
                                                    ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group col-xl-4 col-lg-6 col-md-6">
                                        <label>Data Ditampilkan</label>
                                        <div class="dropdown bootstrap-select wt-search-bar-select dropup">
                                            <select class="wt-search-bar-select selectpicker" name="rank_limit" id="rank_limit">
                                                <option value="10" <?= isset($_GET['rank_limit']) && $_GET['rank_limit'] == '10' ? 'selected' : '' ?>>10</option>
                                                <option value="25" <?= isset($_GET['rank_limit']) && $_GET['rank_limit'] == '25' ? 'selected' : '' ?>>25</option>
                                                <option value="50" <?= isset($_GET['rank_limit']) && $_GET['rank_limit'] == '50' ? 'selected' : '' ?>>50</option>
                                                <option value="100" <?= isset($_GET['rank_limit']) && $_GET['rank_limit'] == '100' ? 'selected' : '' ?>>100</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group col-xl-4 col-lg-6 col-md-6" style="display: flex; gap: 10px;">
                                        <button type="submit" class="site-button">Tampilkan</button>
                                        <a href="/job-board/hc/results" class="site-button text-center">Reset Filter</a>
                                    </div>

                                </div>
                            </form>
                        </div>

                        <hr>

                        <div class="twm-media">
                            <img src="https://gobel.co.id/wp-content/uploads/2022/04/new-logo.png" alt="Candidate">
                        </div>
                        <div class="twm-mid-content">
                        <!--<a href="?clear_cache=1" class="btn btn-danger">Reset Cache</a>-->
                        
                            <h3>Hasil Kandidat Terbaik</h3>
                            <?php if ($selected_job_id): ?>
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Peringkat</th>
                                        <th>Pelamar</th>
                                        <th>Skor Kompetensi</th>
                                        <th>Skor Soft Skills</th>
                                        <th>Skor Wawancara</th>
                                        <th>Total Skor</th>
                                        <th>Skor Akhir</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $candidates = geneticAlgorithm($selected_job_id, $conn, $rank_limit, $score_range);
                                        foreach ($candidates as $candidate): ?>
                                    <tr>
                                        <td><?= $candidate['rank'] ?></td>
                                        <td><a href="/job-board/hc/jobs/detail-apply?id=1&search=<?= $candidate['full_name'] ?>&status=Semua+Status&skor=Semua+Skor&tampil=10" target="_blank"><?= $candidate['applicant_id'] ?> - <?= $candidate['full_name'] ?></a></td>
                                        <td><?= $candidate['score_test'] ?></td>
                                        <td><?= $candidate['soft_skills'] ?></td>
                                        <td><?= $candidate['score_interviews'] ?></td>
                                        <td><?= $candidate['total_score'] ?></td>
                                        <td>
                                            <?= number_format($candidate['final_score'], 2) ?><br> 
                                            <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#detailModal_<?= $candidate['applicant_id'] ?>">Detail</button>
                                        </td>
                                        <td><?= $candidate['status'] ?></td>
                                        <td class="text-nowrap">
                                            <form method='POST'>
                                                <input type="hidden" name="applicant_id" value="<?= $candidate['applicant_id'] ?>">
                                                Apakah Lulus?<br>
                                                <button type="submit" name="Lulus" class="btn btn-primary">Lulus</button>
                                                <button type="submit" name="Ditolak" class="btn btn-danger">Tidak</button>
                                            </form>    
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="detailModal_<?= $candidate['applicant_id'] ?>" tabindex="-1" aria-labelledby="detailModalLabel_<?= $candidate['applicant_id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="detailModalLabel_<?= $candidate['applicant_id'] ?>">Detail Perhitungan - <?= $candidate['full_name'] ?> (ID <?= $candidate['applicant_id'] ?>)</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <h6>Langkah 1: Fuzzifikasi</h6>
    <ul>
        <li><strong>Skor Tes:</strong> Low = <?= round($candidate['details']['test']['low'], 2) ?>, Medium = <?= round($candidate['details']['test']['medium'], 2) ?>, High = <?= round($candidate['details']['test']['high'], 2) ?></li>
        <li><strong>Soft Skills:</strong> Low = <?= round($candidate['details']['skills']['low'], 2) ?>, Medium = <?= round($candidate['details']['skills']['medium'], 2) ?>, High = <?= round($candidate['details']['skills']['high'], 2) ?></li>
        <li><strong>Wawancara:</strong> Low = <?= round($candidate['details']['interviews']['low'], 2) ?>, Medium = <?= round($candidate['details']['interviews']['medium'], 2) ?>, High = <?= round($candidate['details']['interviews']['high'], 2) ?></li>
    </ul>

    <h6>Langkah 2: Kombinasi Aturan Fuzzy</h6>
    <ul>
        <li><strong>Low:</strong> <?= round($candidate['details']['rules']['low'], 2) ?></li>
        <li><strong>Medium:</strong> <?= round($candidate['details']['rules']['medium'], 2) ?></li>
        <li><strong>High:</strong> <?= round($candidate['details']['rules']['high'], 2) ?></li>
    </ul>

    <h6>Langkah 3: Defuzzifikasi</h6>
    <p>
        Rumus: 
        <code>Final Score = (Low * 0.2 + Medium * 0.5 + High * 1.0) / (Low + Medium + High)</code><br>
        Perhitungan: 
        <code>Final Score = (<?= round($candidate['details']['rules']['low'], 2) ?> * 0.2 + <?= round($candidate['details']['rules']['medium'], 2) ?> * 0.5 + <?= round($candidate['details']['rules']['high'], 2) ?> * 1.0) / (<?= round($candidate['details']['rules']['low'], 2) ?> + <?= round($candidate['details']['rules']['medium'], 2) ?> + <?= round($candidate['details']['rules']['high'], 2) ?>)</code>
    </p>
    <p><strong>Final Score:</strong> <?= round($candidate['final_score'], 3) ?></p>
</div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#more">Detail Langkah</button>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                <?php endforeach; ?>
            </tbody>

                </table>
                <?php else: ?>
        <p>Silakan pilih lowongan untuk melihat hasil.</p>
    <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="more">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Contoh perhitungan logika Fuzzy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p>Contoh Data pada peringkat 74 - [170] DIANA PUTRI PERMATA</p>
                <ul>
                    <li>Skor Kompetensi : 95</li>
                    <li>Skor Soft Skills : 40</li>
                    <li>Skor Wawancara : 50</li>
                </ul>
                <a href="https://alumni.gobel.co.id/job-board/assets/img/perhitungan-logika-fuzzy.jpg" target="_blank">
                    <img src="https://alumni.gobel.co.id/job-board/assets/img/perhitungan-logika-fuzzy.jpg">
                </a>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
require '../../job-board/lib/footer.php';
?>
