<?php

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pageTitle = 'Catálogo de Conteúdo';
$pdo = db();

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$temporada = (int) ($_GET['temporada'] ?? 0);
$status = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(e.titulo LIKE ? OR e.observacoes LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($temporada) {
    $where[] = 'e.temporada_id = ?';
    $params[] = $temporada;
}

if ($status !== '') {
    $where[] = 'e.status = ?';
    $params[] = $status;
}

/*
|--------------------------------------------------------------------------
| BUSCA DOS CAPÍTULOS
|--------------------------------------------------------------------------
*/

$sql = '
    SELECT
        e.*,
        t.nome AS temporada
    FROM capitulos e
    LEFT JOIN temporadas t
        ON t.id = e.temporada_id
';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY e.numero';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| TEMPORADAS
|--------------------------------------------------------------------------
*/

$temps = $pdo
    ->query('SELECT * FROM temporadas ORDER BY ordem')
    ->fetchAll();

/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/header.php';

?>

<?php if (isset($_GET['ok'])): ?>

    <div class="alert alert-success auto-hide">
        Registro salvo com sucesso.
    </div>

<?php endif; ?>


<!-- =========================================================
     FILTROS
========================================================= -->

<div class="panel-card mb-4">

    <form class="row g-2 align-items-end">

        <div class="col-md-5">

            <label class="form-label">
                Pesquisar
            </label>

            <input
                name="q"
                value="<?= htmlspecialchars($q) ?>"
                class="form-control"
                placeholder="Título ou observação"
            >

        </div>


        <div class="col-md-3">

            <label class="form-label">
                Temporada
            </label>

            <select
                name="temporada"
                class="form-select"
            >

                <option value="0">
                    Todas
                </option>

                <?php foreach ($temps as $t): ?>

                    <option
                        value="<?= $t['id'] ?>"
                        <?= $temporada == $t['id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($t['nome']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-md-2">

            <label class="form-label">
                Status
            </label>

            <select
                name="status"
                class="form-select"
            >

                <option value="">
                    Todos
                </option>

                <?php foreach (
                    [
                        'Catalogado',
                        'Em edição',
                        'Pronto',
                        'Agendado',
                        'Publicado'
                    ] as $s
                ): ?>

                    <option
                        <?= $status === $s ? 'selected' : '' ?>
                    >
                        <?= $s ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-md-2 d-grid">

            <button class="btn btn-dark">
                Filtrar
            </button>

        </div>

    </form>

</div>


<!-- =========================================================
     CATÁLOGO
========================================================= -->

<div class="panel-card">


    <!-- CABEÇALHO -->

    <div class="d-flex justify-content-between align-items-center mb-3">

        <div>

            <h2 class="h5 mb-0">
                Álbuns / capítulos
            </h2>

            <div class="small text-secondary">
                <?= count($rows) ?> resultado(s)
            </div>

        </div>


        <!-- AÇÕES -->

        <div class="d-flex gap-2">

            <a
                href="drive_sincronizar.php"
                class="btn btn-outline-dark"
                onclick="return confirm(
                    'Sincronizar as pastas do Google Drive com os capítulos do catálogo?'
                )"
            >

                <i class="bi bi-arrow-repeat"></i>

                Sincronizar Drive

            </a>


            <a
                href="capitulo_form.php"
                class="btn btn-dark"
            >

                <i class="bi bi-plus-lg"></i>

                Novo

            </a>

        </div>

    </div>


    <!-- =====================================================
         TABELA
    ====================================================== -->

    <div class="table-responsive">

        <table class="table align-middle">

            <thead>

                <tr>

                    <th>#</th>

                    <th>
                        Álbum
                    </th>

                    <th>
                        Data
                    </th>

                    <th>
                        Arquivos
                    </th>

                    <th>
                        Temporada
                    </th>

                    <th>
                        Produção
                    </th>

                    <th>
                        Status
                    </th>

                    <th></th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($rows as $r): ?>


                <tr>


                    <!-- NÚMERO -->

                    <td>

                        <strong>
                            <?= (int) $r['numero'] ?>
                        </strong>

                    </td>


                    <!-- ÁLBUM -->

                    <td>

                        <a
                            href="capitulo_detalhes.php?id=<?= (int) $r['id'] ?>"
                            class="text-dark text-decoration-none fw-semibold"
                            title="Abrir capítulo"
                        >
                            <?= htmlspecialchars($r['titulo']) ?>
                        </a>


                        <?php if (!empty($r['titulo_publico'])): ?>

                            <div class="small text-success">

                                <i class="bi bi-youtube"></i>

                                <?= htmlspecialchars(
                                    $r['titulo_publico']
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <div class="small text-secondary">

                            <?= htmlspecialchars(
                                $r['etapa'] ?? ''
                            ) ?>

                        </div>

                    </td>


                    <!-- DATA -->

                    <td>

                        <?php if ($r['data_gravacao']): ?>

                            <?= date(
                                'd/m/Y',
                                strtotime($r['data_gravacao'])
                            ) ?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </td>


                    <!-- QUANTIDADE DE ARQUIVOS -->

                    <td>

                        <?= (int) $r['qtd_arquivos'] ?>

                    </td>


                    <!-- TEMPORADA -->

                    <td>

                        <?= htmlspecialchars(
                            $r['temporada'] ?? 'Não definida'
                        ) ?>

                    </td>


                    <!-- PRODUÇÃO -->

                    <td>

                        <?php

                        $ck = !empty($r['producao_checklist'])
                            ? (
                                json_decode(
                                    $r['producao_checklist'],
                                    true
                                ) ?: []
                            )
                            : [];

                        $feito = count(
                            array_filter($ck)
                        );

                        ?>

                        <span class="small text-secondary">

                            <?= $feito ?>/6

                        </span>


                        <div
                            class="progress"
                            style="
                                height:5px;
                                width:70px;
                            "
                        >

                            <div
                                class="progress-bar bg-success"
                                style="
                                    width:
                                    <?= round(
                                        $feito / 6 * 100
                                    ) ?>%
                                "
                            ></div>

                        </div>

                    </td>


                    <!-- STATUS -->

                    <td>

                        <span
                            class="badge text-bg-light border"
                        >

                            <?= htmlspecialchars(
                                $r['status']
                            ) ?>

                        </span>

                    </td>


                    <!-- EDITAR -->

                    <td class="text-end">

                        <a
                            class="btn btn-sm btn-outline-dark"
                            href="capitulo_form.php?id=<?= $r['id'] ?>"
                            title="Editar capítulo"
                        >

                            <i class="bi bi-pencil"></i>

                        </a>

                    </td>


                </tr>


            <?php endforeach; ?>


            <?php if (!$rows): ?>

                <tr>

                    <td
                        colspan="8"
                        class="text-center py-4 text-secondary"
                    >

                        Nenhum capítulo encontrado.

                    </td>

                </tr>

            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


<?php

require __DIR__ . '/includes/footer.php';

?>