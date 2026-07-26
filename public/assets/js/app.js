document.getElementById('menuToggle')?.addEventListener('click',()=>document.getElementById('sidebar')?.classList.toggle('open'));
setTimeout(()=>document.querySelectorAll('.alert.auto-hide').forEach(el=>bootstrap.Alert.getOrCreateInstance(el).close()),3500);
