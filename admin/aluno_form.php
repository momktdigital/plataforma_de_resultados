<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = false;
$aluno = [
    'ra' => '',
    'nome' => '',
    'cpf' => '',
    'data_nascimento' => '',
    'curso' => '',
    'campus' => ''
];

if ($id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM alunos WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fetched) {
            $aluno = $fetched;
            // Converte data do banco para dd/mm/aaaa pro formulário
            if ($aluno['data_nascimento']) {
                $aluno['data_nascimento'] = date('d/m/Y', strtotime($aluno['data_nascimento']));
            }
            $isEdit = true;
        } else {
            die("Aluno não encontrado.");
        }
    } catch (PDOException $e) {
        die("Erro: " . $e->getMessage());
    }
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $ra = trim($_POST['ra'] ?? '');
    $nome = trim($_POST['nome'] ?? '');
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? ''); // Remove máscara
    $data_nascimento_br = trim($_POST['data_nascimento'] ?? '');
    $curso = trim($_POST['curso'] ?? '');
    $campus = trim($_POST['campus'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Validação básica
    if (empty($ra) || empty($cpf) || empty($data_nascimento_br)) {
        $erro = "Os campos RA, CPF e Data de Nascimento são obrigatórios.";
    } elseif (strlen($cpf) !== 11) {
        $erro = "CPF inválido.";
    } else {
        // Converte DD/MM/YYYY para YYYY-MM-DD
        $data_nascimento = null;
        $dateParts = explode('/', $data_nascimento_br);
        if (count($dateParts) === 3) {
            $data_nascimento = "{$dateParts[2]}-{$dateParts[1]}-{$dateParts[0]}";
        } else {
            $erro = "Data de nascimento inválida.";
        }

        if (!$erro) {
            try {
                if ($isEdit) {
                    $stmt = $conn->prepare("UPDATE alunos SET ra = :ra, nome = :nome, cpf = :cpf, data_nascimento = :data_nascimento, curso = :curso, campus = :campus, email = :email WHERE id = :id");
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                } else {
                    $stmt = $conn->prepare("INSERT INTO alunos (ra, nome, cpf, data_nascimento, curso, campus, email) VALUES (:ra, :nome, :cpf, :data_nascimento, :curso, :campus, :email)");
                }

                $stmt->bindParam(':ra', $ra, PDO::PARAM_STR);
                $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
                $stmt->bindParam(':cpf', $cpf, PDO::PARAM_STR);
                $stmt->bindParam(':data_nascimento', $data_nascimento, PDO::PARAM_STR);
                $stmt->bindParam(':curso', $curso, PDO::PARAM_STR);
                $stmt->bindParam(':campus', $campus, PDO::PARAM_STR);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);

                $stmt->execute();

                header("Location: alunos.php?success=1&msg=" . urlencode("Aluno salvo com sucesso."));
                exit();

            } catch (PDOException $e) {
                // Checa erro de chave duplicada
                if ($e->getCode() == 23000) {
                    $erro = "Já existe um aluno cadastrado com este RA ou CPF.";
                } else {
                    $erro = "Erro ao salvar: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<div class="mb-8 flex items-center justify-between">
    <div>
        <a href="alunos.php" class="text-slate-500 hover:text-primary flex items-center text-sm font-medium mb-2 transition-colors">
            <i class="ph-bold ph-arrow-left mr-2"></i> Voltar para Alunos
        </a>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">
            <?= $isEdit ? 'Editar Aluno' : 'Novo Aluno' ?>
        </h1>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-3xl">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-student text-[#00b48d]"></i> Dados Cadastrais
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <?php if ($erro): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($erro) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <!-- RA -->
                <div>
                    <label for="ra" class="block text-sm font-bold text-slate-700 mb-1">Registro Acadêmico (RA) <span class="text-red-500">*</span></label>
                    <input type="text" id="ra" name="ra" required value="<?= htmlspecialchars($aluno['ra'] ?? ($_POST['ra'] ?? '')) ?>"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-slate-500 mt-1">Identificador único no sistema de notas.</p>
                </div>

                <!-- Nome -->
                <div>
                    <label for="nome" class="block text-sm font-bold text-slate-700 mb-1">Nome Completo</label>
                    <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($aluno['nome'] ?? ($_POST['nome'] ?? '')) ?>"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <!-- CPF -->
                <div>
                    <label for="cpf" class="block text-sm font-bold text-slate-700 mb-1">CPF <span class="text-red-500">*</span></label>
                    <input type="text" id="cpf" name="cpf" required value="<?= htmlspecialchars($aluno['cpf'] ?? ($_POST['cpf'] ?? '')) ?>"
                           placeholder="000.000.000-00"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-slate-500 mt-1">Será usado como login pelo aluno.</p>
                </div>

                <!-- Data de Nascimento -->
                <div>
                    <label for="data_nascimento" class="block text-sm font-bold text-slate-700 mb-1">Data de Nascimento <span class="text-red-500">*</span></label>
                    <input type="text" id="data_nascimento" name="data_nascimento" required value="<?= htmlspecialchars($aluno['data_nascimento'] ?? ($_POST['data_nascimento'] ?? '')) ?>"
                           placeholder="DD/MM/AAAA"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-slate-500 mt-1">Senha de acesso do aluno.</p>
                </div>

                <!-- Curso -->
                <div>
                    <label for="curso" class="block text-sm font-bold text-slate-700 mb-1">Curso</label>
                    <input type="text" id="curso" name="curso" value="<?= htmlspecialchars($aluno['curso'] ?? ($_POST['curso'] ?? '')) ?>"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <!-- Campus -->
                <div>
                    <label for="campus" class="block text-sm font-bold text-slate-700 mb-1">Câmpus / Polo</label>
                    <input type="text" id="campus" name="campus" value="<?= htmlspecialchars($aluno['campus'] ?? ($_POST['campus'] ?? '')) ?>"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <!-- E-mail -->
                <div>
                    <label for="email" class="block text-sm font-bold text-slate-700 mb-1">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($aluno['email'] ?? ($_POST['email'] ?? '')) ?>"
                           class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
            </div>

            <div class="mt-8 flex justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="alunos.php" class="px-5 py-2.5 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-medium hover:bg-slate-50 transition-colors">
                    Cancelar
                </a>
                <button type="submit" class="px-5 py-2.5 bg-primary text-white rounded-lg text-sm font-bold shadow-sm hover:bg-emerald-600 focus:ring-2 focus:ring-primary focus:ring-offset-2 transition-colors flex items-center">
                    <i class="ph-bold ph-floppy-disk mr-2"></i> Salvar Cadastro
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Masking logic -->
<script src="https://unpkg.com/imask"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const cpfInput = document.getElementById('cpf');
        const dataNascimentoInput = document.getElementById('data_nascimento');

        IMask(cpfInput, { mask: '000.000.000-00' });
        IMask(dataNascimentoInput, {
            mask: Date,
            pattern: 'd/m/Y',
            blocks: {
                d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2 },
                m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
                Y: { mask: IMask.MaskedRange, from: 1900, to: 2999 }
            },
            format: function (date) {
                var day = date.getDate();
                var month = date.getMonth() + 1;
                var year = date.getFullYear();

                if (day < 10) day = "0" + day;
                if (month < 10) month = "0" + month;

                return [day, month, year].join('/');
            },
            parse: function (str) {
                var yearMonthDay = str.split('/');
                return new Date(yearMonthDay[2], yearMonthDay[1] - 1, yearMonthDay[0]);
            }
        });
    });
</script>

<?php require_once 'includes/footer.php'; ?>
