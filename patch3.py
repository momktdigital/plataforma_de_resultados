with open("admin/resultados.php", "r") as f:
    content = f.read()

# I want to add avaliacao to the search GET params handling
search_str = """$search = $_GET['search'] ?? '';

$whereClause = "";
$params = [];

if ($search) {
    $whereClause = "WHERE r.ra LIKE :search OR r.periodo LIKE :search OR r.nome_avaliacao LIKE :search";
    $params[':search'] = "%$search%";
}"""

replace_str = """$search = $_GET['search'] ?? '';

$whereClause = "";
$params = [];

if ($search) {
    $whereClause = "WHERE r.ra LIKE :search OR r.periodo LIKE :search OR r.nome_avaliacao LIKE :search";
    $params[':search'] = "%$search%";
}"""

content = content.replace(search_str, replace_str)

with open("admin/resultados.php", "w") as f:
    f.write(content)
