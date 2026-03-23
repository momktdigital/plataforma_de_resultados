<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mock Aluno Editar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="p-6 flex flex-col flex-1">
            <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-3 overflow-y-auto mb-6 pr-2" style="max-height: calc(100vh - 200px); align-content: start;">
                <div class="flex flex-col border border-slate-200 rounded overflow-hidden focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all shrink-0 bg-white">
                    <label class="block w-full bg-slate-100 text-[10px] text-center font-bold text-slate-500 py-1 border-b border-slate-200 m-0">Q1</label>
                    <input type="text" name="Q1" value="A" maxlength="1" class="block w-full text-center py-2 text-2xl leading-[1] font-black text-slate-800 focus:outline-none uppercase bg-white m-0 border-0">
                </div>
                <div class="flex flex-col border border-slate-200 rounded overflow-hidden focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all shrink-0 bg-white">
                    <label class="block w-full bg-slate-100 text-[10px] text-center font-bold text-slate-500 py-1 border-b border-slate-200 m-0">Q2</label>
                    <input type="text" name="Q2" value="B" maxlength="1" class="block w-full text-center py-2 text-2xl leading-[1] font-black text-slate-800 focus:outline-none uppercase bg-white m-0 border-0">
                </div>
                <div class="flex flex-col border border-slate-200 rounded overflow-hidden focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all shrink-0 bg-white">
                    <label class="block w-full bg-slate-100 text-[10px] text-center font-bold text-slate-500 py-1 border-b border-slate-200 m-0">Q3</label>
                    <input type="text" name="Q3" value="C" maxlength="1" class="block w-full text-center py-2 text-2xl leading-[1] font-black text-slate-800 focus:outline-none uppercase bg-white m-0 border-0">
                </div>
            </div>
        </div>
    </div>
</body>
</html>
