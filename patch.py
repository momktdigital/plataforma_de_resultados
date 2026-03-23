with open("admin/includes/header.php", "r") as f:
    content = f.read()

bad_nav = """                <li>
                    <a href="upload_form.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'upload_form.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-cloud-arrow-up text-xl mr-3 <?= $current_page === 'upload_form.php' ? 'text-primary' : '' ?>"></i> Upload de CSV
                <li>

                    <a href="upload_gabarito.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'upload_gabarito.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">

                        <i class="ph ph-check-square-offset text-xl mr-3 <?= $current_page === 'upload_gabarito.php' ? 'text-primary' : '' ?>"></i> Gabaritos

                    </a>

                </li>
                    </a>
                </li>"""

good_nav = """                <li>
                    <a href="upload_form.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'upload_form.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-cloud-arrow-up text-xl mr-3 <?= $current_page === 'upload_form.php' ? 'text-primary' : '' ?>"></i> Upload de CSV
                    </a>
                </li>
                <li>
                    <a href="upload_gabarito.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'upload_gabarito.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-check-square-offset text-xl mr-3 <?= $current_page === 'upload_gabarito.php' ? 'text-primary' : '' ?>"></i> Gabaritos
                    </a>
                </li>"""

content = content.replace(bad_nav, good_nav)

with open("admin/includes/header.php", "w") as f:
    f.write(content)
