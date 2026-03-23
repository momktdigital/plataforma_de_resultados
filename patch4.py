with open("admin/resultados.php", "r") as f:
    content = f.read()

# Replace variables related to search
# I want to add avaliacao to the search GET params handling
search_str = """$searchRa = $_GET['search'] ?? '';
$filterPeriodo = $_GET['periodo'] ?? '';"""

replace_str = """$searchRa = $_GET['search'] ?? '';
$filterPeriodo = $_GET['periodo'] ?? '';
$filterAvaliacao = $_GET['avaliacao'] ?? '';"""

content = content.replace(search_str, replace_str)

search_str2 = """if (!empty($searchRa)) {
    $where[] = "ra LIKE :ra";
    $params[':ra'] = "%$searchRa%";
}
if (!empty($filterPeriodo)) {
    $where[] = "periodo = :periodo";
    $params[':periodo'] = $filterPeriodo;
}"""

replace_str2 = """if (!empty($searchRa)) {
    $where[] = "r.ra LIKE :ra";
    $params[':ra'] = "%$searchRa%";
}
if (!empty($filterPeriodo)) {
    $where[] = "r.periodo = :periodo";
    $params[':periodo'] = $filterPeriodo;
}
if (!empty($filterAvaliacao)) {
    $where[] = "r.nome_avaliacao = :avaliacao";
    $params[':avaliacao'] = $filterAvaliacao;
}"""

content = content.replace(search_str2, replace_str2)

with open("admin/resultados.php", "w") as f:
    f.write(content)
