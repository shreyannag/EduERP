<?php
// admin/questions/upload.php
require_once '../../includes/admin_header.php';

require_admin_feature('upload_csv');

$valid_boards = ['WB Board', 'CBSE', 'ICSE', 'ISC'];
$valid_classes = ['8', '9', '10', '11', '12'];

$success_msg = '';
$error_msg = '';
$preview_data = [];
$filename_tmp = '';

// Step 1: Handle File Upload & Parse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $file = $_FILES['csv_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error_msg = "Invalid file type. Please upload a .csv file.";
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $error_msg = "File exceeds 2 MB limit.";
        } else {
            $uploads_dir = '../../uploads/csv';
            if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0777, true);
            $filename_tmp = uniqid('csv_') . '.csv';
            $dest = $uploads_dir . '/' . $filename_tmp;
            
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if (($handle = fopen($dest, 'r')) !== FALSE) {
                    $header = fgetcsv($handle, 0, ',', '"', '');
                    // Standardize BOM and trim to avoid invisible comparison fails
                    $clean_header = array_map(function($val) {
                        return trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string)$val));
                    }, $header ?: []);

                    $header_base = implode(',', array_slice($clean_header, 0, 8));
                    if ($header_base !== 'Class,Board,Question,Option1,Option2,Option3,Option4,CorrectOption' 
                        && $header_base !== 'Class,Board,Question,Option 1,Option 2,Option 3,Option 4,Correct Option') {
                        $error_msg = "Invalid CSV header format. Please check the sample file.";
                        // fallback check to allow slight variations if user used standard sample
                    }
                    
                    if(!$error_msg) {
                        $rowNum = 2;
                        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
                            if (count($data) < 8) continue; // Skip malformed trailing lines
                            
                            $class = trim($data[0]);
                            $board = trim($data[1]);
                            $question = trim($data[2]);
                            $opt1 = trim($data[3]);
                            $opt2 = trim($data[4]);
                            $opt3 = trim($data[5]);
                            $opt4 = trim($data[6]);
                            $correct = trim($data[7]);
                            
                            $isValid = true;
                            $errorText = [];
                            
                            $is_fill_blank = false;
                            $q_format = (isset($data[8]) && strtolower(trim($data[8])) === 'fill_blank') ? 'fill_blank' : '';
                            if ($q_format === 'fill_blank' || ($opt2 === '' && $opt3 === '' && $opt4 === '' && ($opt1 !== '' || $correct !== ''))) {
                                $is_fill_blank = true;
                            }

                            if (!in_array($class, $valid_classes)) { $isValid = false; $errorText[] = "Invalid Class"; }
                            if (!in_array($board, $valid_boards)) { $isValid = false; $errorText[] = "Invalid Board: " . htmlspecialchars($board); }
                            if ($question === '') { $isValid = false; $errorText[] = "Empty Question"; }

                            $blank_ans = '';
                            $blank_answers = [];
                            if ($is_fill_blank) {
                                if ($q_format === 'fill_blank' && ($opt2 !== '' || $opt3 !== '' || $opt4 !== '')) {
                                    $candidates = [$opt1, $opt2, $opt3, $opt4];
                                    foreach ($candidates as $cand) {
                                        if ($cand !== '') $blank_answers[] = $cand;
                                    }
                                } elseif ($opt1 !== '') {
                                    $blank_answers = array_values(parse_fill_blank_answers($opt1));
                                } elseif ($correct !== '') {
                                    $blank_answers = array_values(parse_fill_blank_answers($correct));
                                }

                                if (empty($blank_answers)) {
                                    $isValid = false;
                                    $errorText[] = "Fill-in-the-blank answer cannot be empty";
                                } else {
                                    $blank_ans = count($blank_answers) > 1 ? json_encode($blank_answers, JSON_UNESCAPED_UNICODE) : $blank_answers[0];
                                }
                            } else {
                                if ($opt1 === '' || $opt2 === '' || $opt3 === '' || $opt4 === '') { $isValid = false; $errorText[] = "Options cannot be empty"; }
                                if (!in_array($correct, ['1','2','3','4'])) { $isValid = false; $errorText[] = "Correct Option must be 1-4"; }
                            }
                            
                            $preview_data[] = [
                                'row' => $rowNum,
                                'class' => $class,
                                'board' => $board,
                                'question' => $question,
                                'is_fill_blank' => $is_fill_blank,
                                'blank_answer' => $blank_ans,
                                'options' => [$opt1, $opt2, $opt3, $opt4],
                                'correct' => $correct,
                                'is_valid' => $isValid,
                                'error' => implode(', ', $errorText)
                            ];
                            $rowNum++;
                        }
                    }
                    fclose($handle);
                } else {
                    $error_msg = "Failed to read the uploaded file.";
                }
            } else {
                $error_msg = "Failed to save the uploaded file.";
            }
        }
    } else {
        $error_msg = "File upload error code: " . escapeshellarg($file['error']);
    }
}

// Step 2: Handle Final Import Commit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $dest = '../../uploads/csv/' . $_POST['temp_file'];
    if (file_exists($dest)) {
        $classMap = [];
        $stmt_c = $pdo->query("SELECT id, name FROM classes");
        while($r = $stmt_c->fetch()) $classMap[$r['name']] = $r['id'];
        
        $boardMap = [];
        $stmt_b = $pdo->query("SELECT id, name FROM boards");
        while($r = $stmt_b->fetch()) $boardMap[$r['name']] = $r['id'];

        $imported = 0;
        $skipped = 0;
        
        $current_admin_id = (int)$_SESSION['admin_id'];
        
        if (($handle = fopen($dest, 'r')) !== FALSE) {
            fgetcsv($handle, 0, ',', '"', ''); 
            
            $stmt_check = $pdo->prepare("SELECT id FROM questions WHERE admin_id = ? AND class_id = ? AND board_id = ? AND TRIM(question_text) = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO questions (admin_id, class_id, board_id, question_text, option1, option2, option3, option4, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            while (($data = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
                if(count($data) < 8) continue;
                
                $class = trim($data[0]);
                $board = trim($data[1]);
                $question = trim($data[2]);
                $opt1 = trim($data[3]);
                $opt2 = trim($data[4]);
                $opt3 = trim($data[5]);
                $opt4 = trim($data[6]);
                $correct = trim($data[7]);
                
                $c_id = $classMap[$class] ?? null;
                $b_id = $boardMap[$board] ?? null;
                if ($c_id && $b_id && $question !== '') {
                    $stmt_check->execute([$current_admin_id, $c_id, $b_id, $question]);
                    if ($stmt_check->fetch()) {
                        $skipped++; 
                        continue;
                    }

                    $is_fill_blank = false;
                    $q_format = (isset($data[8]) && strtolower(trim($data[8])) === 'fill_blank') ? 'fill_blank' : '';
                    if ($q_format === 'fill_blank' || ($opt2 === '' && $opt3 === '' && $opt4 === '' && ($opt1 !== '' || $correct !== ''))) {
                        $is_fill_blank = true;
                    }

                    $new_upload_id = 0;
                    if ($is_fill_blank) {
                        $blank_answers = [];
                        if ($q_format === 'fill_blank' && ($opt2 !== '' || $opt3 !== '' || $opt4 !== '')) {
                            $candidates = [$opt1, $opt2, $opt3, $opt4];
                            foreach ($candidates as $cand) {
                                if ($cand !== '') $blank_answers[] = $cand;
                            }
                        } elseif ($opt1 !== '') {
                            $blank_answers = array_values(parse_fill_blank_answers($opt1));
                        } elseif ($correct !== '') {
                            $blank_answers = array_values(parse_fill_blank_answers($correct));
                        }

                        if (!empty($blank_answers)) {
                            $blank_ans_save = count($blank_answers) > 1 ? json_encode($blank_answers, JSON_UNESCAPED_UNICODE) : $blank_answers[0];
                            $opt1_display = implode(' / ', $blank_answers);
                            $stmt_ins_blank = $pdo->prepare("INSERT INTO questions (admin_id, class_id, board_id, question_type, question_text, option1, correct_answer_text) VALUES (?, ?, ?, 'fill_blank', ?, ?, ?)");
                            $stmt_ins_blank->execute([$current_admin_id, $c_id, $b_id, $question, $opt1_display, $blank_ans_save]);
                            $new_upload_id = (int)$pdo->lastInsertId();
                            $imported++;
                        }
                    } else {
                        if ($opt1 !== '' && $opt2 !== '' && $opt3 !== '' && $opt4 !== '' && in_array($correct, ['1','2','3','4'])) {
                            $stmt_ins_mcq = $pdo->prepare("INSERT INTO questions (admin_id, class_id, board_id, question_type, question_text, option1, option2, option3, option4, correct_option) VALUES (?, ?, ?, 'mcq', ?, ?, ?, ?, ?, ?)");
                            $stmt_ins_mcq->execute([$current_admin_id, $c_id, $b_id, $question, $opt1, $opt2, $opt3, $opt4, $correct]);
                            $new_upload_id = (int)$pdo->lastInsertId();
                            $imported++;
                        }
                    }

                    if ($new_upload_id > 0) {
                        $pdo->prepare("INSERT IGNORE INTO question_classes (question_id, class_id) VALUES (?, ?)")->execute([$new_upload_id, $c_id]);
                    }
                }
            }
            fclose($handle);
            unlink($dest); 
            $success_msg = "$imported questions successfully imported. $skipped duplicate rows skipped.";
            $preview_data = []; 
        }
    } else {
        $error_msg = "Import session expired or file missing. Please re-upload.";
    }
}

// Cancel Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_import'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $dest = '../../uploads/csv/' . $_POST['temp_file'];
    if (file_exists($dest)) unlink($dest);
    $preview_data = [];
    $error_msg = "Import cancelled. The temporary file was removed.";
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold">Upload Questions <span class="text-muted font-weight-normal h4">CSV Import</span></h2>
    <a href="<?php echo BASE_URL; ?>/uploads/csv/sample_questions.csv" class="btn btn-outline-info font-weight-bold" download>
        <i class="fas fa-file-download mr-1"></i> Download Sample CSV
    </a>
</div>

<div class="mb-4">
    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm rounded-lg border-0 py-3"><i class="fas fa-check-circle mr-2 fa-lg"></i><?php echo h($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm rounded-lg border-0 py-3"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error_msg); ?></div>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm rounded-lg mb-4 bg-light border-left border-primary" style="border-left-width: 4px !important;">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h5 class="font-weight-bold text-dark mb-1">
                    <i class="fas fa-square-root-alt text-primary mr-2"></i>LaTeX & Mathematical Equations Support in CSV
                </h5>
                <span class="text-muted small">Write standard LaTeX formulas in Question and Options using <code>$...$</code> or <code>$$...$$</code>.</span>
            </div>
            <button class="btn btn-sm btn-outline-primary rounded-pill font-weight-bold mt-2 mt-md-0" type="button" data-toggle="collapse" data-target="#latexHelpCollapse">
                <i class="fas fa-info-circle mr-1"></i>View LaTeX CSV Syntax & Samples
            </button>
        </div>
        <div class="collapse mt-3" id="latexHelpCollapse">
            <hr class="my-2">
            <div class="row pt-2">
                <div class="col-md-3 mb-2">
                    <div class="p-2 bg-white rounded border small h-100 shadow-sm">
                        <strong class="d-block text-primary mb-1"><i class="fas fa-divide mr-1"></i>Fractions & Roots:</strong>
                        <code>$\frac{a}{b}$</code><br>
                        <code>$\sqrt{x^2+y^2}$</code>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="p-2 bg-white rounded border small h-100 shadow-sm">
                        <strong class="d-block text-primary mb-1"><i class="fas fa-superscript mr-1"></i>Exponents & Quad:</strong>
                        <code>$ax^2 + bx + c = 0$</code><br>
                        <code>$x_i^{2}$</code>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="p-2 bg-white rounded border small h-100 shadow-sm">
                        <strong class="d-block text-primary mb-1"><i class="fas fa-infinity mr-1"></i>Calculus & Greek:</strong>
                        <code>$\int_0^\pi \sin(x) dx$</code><br>
                        <code>$\alpha, \beta, \omega, \theta$</code>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="p-2 bg-white rounded border small h-100 shadow-sm">
                        <strong class="d-block text-primary mb-1"><i class="fas fa-flask mr-1"></i>Chemistry & Vectors:</strong>
                        <code>$\text{H}_2\text{O}$</code><br>
                        <code>$\vec{F} = m\vec{a}$</code>
                    </div>
                </div>
            </div>
            <div class="alert alert-info py-2 px-3 small rounded mb-0 mt-3 border-0">
                <i class="fas fa-lightbulb mr-1 text-warning"></i> <strong>CSV Pro Tip:</strong> If your formula contains a comma or quote, enclose the CSV field in double quotes: <code>"$\frac{-b \pm \sqrt{b^2 - 4ac}}{2a}$"</code>.
            </div>
        </div>
    </div>
</div>

<?php if (empty($preview_data) && !isset($_POST['confirm_import'])): ?>
<!-- Upload Form -->
<div class="card shadow-sm border-0 mb-4 rounded-lg">
    <div class="card-body p-5">
        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate id="uploadForm">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            
            <div class="border rounded p-4 mb-4 text-center bg-light border-dashed" style="border-style: dashed !important; border-width: 2px !important;">
                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                <div class="form-group mb-0">
                    <div class="custom-file w-75 mx-auto text-left">
                        <input type="file" name="csv_file" class="custom-file-input" id="csvFile" accept=".csv" required>
                        <label class="custom-file-label" for="csvFile">Choose CSV file to import...</label>
                        <div class="invalid-feedback text-center mt-2">Please select a valid CSV file.</div>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">Max file size: 2MB. Mathematical LaTeX formulas in <code>$...$</code> are fully supported.</p>
            </div>
            
            <div class="text-center">
                <button type="submit" class="btn btn-primary btn-lg font-weight-bold px-5 shadow-sm rounded-pill">
                    <i class="fas fa-search mr-2"></i> Upload & Preview
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($preview_data)): ?>
<!-- Preview Table -->
<div class="card shadow-sm border-0 mb-4 rounded-lg">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="font-weight-bold text-dark mb-1">Preview Data & LaTeX Formulas</h5>
            <p class="text-muted small mb-0">Review extracted questions and rendered mathematical equations before storing. Invalid rows will be skipped.</p>
        </div>
        <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold"><i class="fas fa-square-root-alt mr-1"></i>LaTeX Typeset Active</span>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="px-4" width="5%">#</th>
                        <th width="8%">Class</th>
                        <th width="10%">Board</th>
                        <th width="50%">Question & Options Preview</th>
                        <th width="12%">Correct</th>
                        <th class="pr-4 text-right" width="15%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_data as $row): ?>
                        <tr class="<?php echo $row['is_valid'] ? '' : 'table-danger'; ?>">
                            <td class="px-4 align-middle"><?php echo $row['row']; ?></td>
                            <td class="align-middle font-weight-bold"><span class="badge badge-light border">Class <?php echo h($row['class']); ?></span></td>
                            <td class="align-middle"><span class="badge badge-light border text-primary"><?php echo h($row['board']); ?></span></td>
                            <td class="align-middle">
                                <div class="font-weight-500 text-dark mb-2" style="font-size: 1.05rem; line-height: 1.6;">
                                    <?php if (!empty($row['is_fill_blank'])): ?>
                                        <span class="badge badge-warning text-dark font-weight-bold mr-1"><i class="fas fa-pen-alt mr-1"></i>Fill Blank</span>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-secondary font-weight-bold mr-1">MCQ</span>
                                    <?php endif; ?>
                                    <?php echo render_formatted_content($row['question']); ?>
                                </div>
                                <?php if (!empty($row['is_fill_blank'])): ?>
                                    <div class="small text-muted mt-1"><i class="fas fa-key text-success mr-1"></i>Accepted Answer: <strong class="text-success"><?php echo h($row['blank_answer']); ?></strong></div>
                                <?php else: ?>
                                    <button class="btn btn-xs btn-outline-secondary py-1 px-3 rounded-pill font-weight-bold shadow-sm" type="button" data-toggle="collapse" data-target="#opt-details-<?php echo $row['row']; ?>">
                                        <i class="fas fa-list-ol mr-1"></i>Toggle 4 Options & Formulas
                                    </button>
                                    <div class="collapse mt-2" id="opt-details-<?php echo $row['row']; ?>">
                                        <div class="p-3 bg-white rounded border shadow-sm small">
                                            <div class="row">
                                                <?php foreach($row['options'] as $idx => $opt): 
                                                    $isC = (($idx + 1) == (int)$row['correct']);
                                                ?>
                                                <div class="col-md-6 mb-2">
                                                    <div class="p-2 rounded border <?php echo $isC ? 'bg-success bg-opacity-10 border-success font-weight-bold' : 'bg-light'; ?>">
                                                        <span class="badge <?php echo $isC ? 'badge-success' : 'badge-secondary'; ?> mr-2"><?php echo chr(65 + $idx); ?></span>
                                                        <span class="<?php echo $isC ? 'text-success' : 'text-dark'; ?>"><?php echo render_formatted_content($opt); ?></span>
                                                        <?php if($isC): ?><span class="badge badge-success ml-2 py-0 px-1 float-right">Correct</span><?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <?php if (!empty($row['is_fill_blank'])): ?>
                                    <span class="badge badge-success px-2 py-1 shadow-sm font-weight-bold text-truncate" style="max-width: 130px;" title="<?php echo h($row['blank_answer']); ?>"><i class="fas fa-check mr-1"></i><?php echo h($row['blank_answer']); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success px-3 py-2 shadow-sm font-weight-bold">Option <?php echo h($row['correct']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="pr-4 align-middle text-right">
                                <?php if ($row['is_valid']): ?>
                                    <span class="badge badge-success px-3 py-1 shadow-sm"><i class="fas fa-check mr-1"></i> Valid</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 py-1 shadow-sm" title="<?php echo h($row['error']); ?>"><i class="fas fa-times mr-1"></i> Invalid</span>
                                    <div class="small text-danger mt-1 text-right font-weight-bold"><?php echo h($row['error']); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-light p-4 d-flex justify-content-between align-items-center rounded-bottom">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            <input type="hidden" name="temp_file" value="<?php echo h($filename_tmp); ?>">
            <button type="submit" name="cancel_import" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4"><i class="fas fa-times mr-2"></i>Cancel Import</button>
        </form>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            <input type="hidden" name="temp_file" value="<?php echo h($filename_tmp); ?>">
            <button type="submit" name="confirm_import" class="btn btn-success font-weight-bold px-5 shadow rounded-pill"><i class="fas fa-check-double mr-2"></i>Confirm & Save Valid Rows</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelector('.custom-file-input')?.addEventListener('change', function(e) {
    var fileName = document.getElementById("csvFile").files[0].name;
    var nextSibling = e.target.nextElementSibling;
    nextSibling.innerText = fileName;
});

$(document).ready(function() {
    if (window.MathJax && window.MathJax.typesetPromise) {
        MathJax.typesetPromise();
    }
    $('.collapse').on('shown.bs.collapse', function () {
        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([this]);
        }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>
