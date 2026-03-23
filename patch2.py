with open("admin/upload_form.php", "r") as f:
    content = f.read()

bad_input = """            <div class="mb-8">
                <label class="block text-sm font-bold text-slate-700 mb-3">3. Selecione o arquivo .CSV <span class="text-red-500">*</span></label>
                <label class="block text-sm font-bold text-slate-700 mb-2">1. Nome da Avaliação <span class="text-red-500">*</span></label>
                    <div class="space-y-2 text-center">"""

good_input = """            <div class="mb-8 border border-dashed border-slate-300 rounded-xl p-8 hover:bg-slate-50 transition-colors group">
                <label class="block text-sm font-bold text-slate-700 mb-3 text-center">3. Selecione o arquivo .CSV <span class="text-red-500">*</span></label>
                    <div class="space-y-2 text-center">"""

content = content.replace(bad_input, good_input)

with open("admin/upload_form.php", "w") as f:
    f.write(content)
