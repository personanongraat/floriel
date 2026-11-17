
function installFlorist({catalog, image, refresh}) {
 const $ = (selector, root=document) => root.querySelector(selector);
 const bar = $('#florist-bar'), dialog = $('#florist-dialog');
 const categories = catalog.categories.slice(1);
 let editing = false, previewURL;
 const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

 async function request(url, method='GET', data) {
  const headers = method === 'GET' ? {} : {'X-Floriel-Request':'editor'};
  if (data) headers['Content-Type'] = 'application/json';
  const res = await fetch(url,{method,headers,body:data ? JSON.stringify(data) : undefined,cache:'no-store'});
  const result = await res.json();
  if (!res.ok) throw Error(result.error);
  return result;
 }
 const message = text => { $('[role=status]',bar).textContent = text; };
 function update(result, notice='') {
  editing = result.editor;
  catalog.products = result.products;
  bar.classList.toggle('active',editing);
  bar.innerHTML = (editing ?
   '<strong>Режим флориста</strong><div class="florist-actions"><button class="button" data-action="add">＋ Добавить букет</button><button class="florist-link" data-action="exit">Выйти</button></div>' :
   '<button class="florist-link" data-action="enter">Войти как флорист</button>') + '<p role="status"></p>';
  message(notice);
  refresh();
 }
 function showForm(title, fields, label, submit) {
  dialog.innerHTML = '<form><h2 id="florist-dialog-title">' + title + '</h2>' + fields +
   '<p class="florist-error" role="alert"></p><div class="florist-form-actions">' +
   '<button class="button" type="submit">' + label + '</button><button class="florist-link" type="button">Отмена</button></div></form>';
  const form = $('form',dialog), save = $('[type=submit]',form);
  $('[type=button]',form).onclick = () => dialog.close();
  form.onsubmit = async event => {
   event.preventDefault();
   const error = $('.florist-error',form);
   error.textContent = '';
   save.disabled = true;
   try { await submit(form); }
   catch (e) { error.textContent = e.message || 'Не удалось выполнить действие.'; }
   finally { save.disabled = false; }
  };
  dialog.showModal();
  return form;
 }
 function login() {
  showForm('Вход для флориста',
   '<p class="muted">Пароль предпросмотра <code>floriel-demo</code></p><label>Пароль<input name="password" type="password" autocomplete="current-password" required autofocus></label>',
   'Войти',async form => {
    const result = await request('/api/login','POST',{password:form.elements.password.value});
    dialog.close();
    update(result,'Можно менять букеты прямо в каталоге.');
    $('#catalog').scrollIntoView({block:'start'});
   });
 }
 function photoData(file) {
  return new Promise((resolve,reject) => {
   const reader = new FileReader();
   reader.onload = () => resolve(reader.result);
   reader.onerror = () => reject(Error('Не удалось прочитать фотографию.'));
   reader.readAsDataURL(file);
  });
 }
 function edit(product) {
  const options = categories.map(name => '<option>' + esc(name) + '</option>').join('');
  const form = showForm(product ? 'Изменить букет' : 'Добавить букет',
   '<div class="florist-grid"><label class="wide">Название<input name="name" maxlength="80" required autofocus></label>' +
   '<label>Цена, ₽<input name="price" type="number" min="1" max="1000000" required></label>' +
   '<label>Категория<select name="category">' + options + '</select></label>' +
   '<label class="wide">Состав<textarea name="composition" rows="2" maxlength="500" required></textarea></label>' +
   '<div class="wide florist-photo"><img alt="Предпросмотр фото" hidden><label>Фотография<input name="photo" type="file" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG или WebP до 5 МБ</small></label></div>' +
   '<label class="wide florist-check"><input name="inCatalog" type="checkbox"> Показывать покупателям</label></div>',
   'Сохранить букет',async form => {
    const f = form.elements;
    const body = {name:f.name.value,price:f.price.value,category:f.category.value,composition:f.composition.value,inCatalog:f.inCatalog.checked};
    const file = f.photo.files[0];
    if (file) {
     if (file.size > 5 * 1024 * 1024) throw Error('Максимальный размер фотографии — 5 МБ.');
     body.photo = await photoData(file);
    }
    const result = await request(product ? '/api/bouquets/'+product.id : '/api/bouquets',product ? 'PUT' : 'POST',body);
    dialog.close();
    $('#filters button')?.click();
    update(result,product ? 'Изменения сохранены.' : 'Букет добавлен.');
   });
  const f = form.elements, picture = $('img',form);
  f.name.value = product?.name || '';
  f.price.value = product?.price ?? '';
  f.category.value = product?.category || categories[0];
  f.composition.value = product?.composition || '';
  f.inCatalog.checked = product?.inCatalog !== false;
  f.photo.required = !product;
  const initial = product ? image(product.image) : '';
  const display = src => { picture.hidden = !src; if (src) picture.src=src; else picture.removeAttribute('src'); };
  display(initial);
  f.photo.onchange = () => {
   if (previewURL) URL.revokeObjectURL(previewURL);
   previewURL = f.photo.files[0] ? URL.createObjectURL(f.photo.files[0]) : '';
   display(previewURL || initial);
  };
 }
 dialog.addEventListener('close', () => {
  if (previewURL) URL.revokeObjectURL(previewURL);
  previewURL = '';
 });
 bar.onclick = async event => {
  const action = event.target.closest('[data-action]')?.dataset.action;
  if (action === 'enter') return login();
  if (action === 'add') return edit();
  if (action === 'exit') {
   try {
    update(await request('/api/logout','POST'));
    if (location.pathname.startsWith('/admin')) history.replaceState(null,'','/#catalog');
   } catch (e) { message(e.message); }
  }
 };
 $('#products').addEventListener('click',async event => {
  const target = event.target.closest('[data-edit-id]');
  if (!target || !editing) return;
  const product = catalog.products.find(p => p.id === target.dataset.editId);
  if (!product) return;
  const action = target.dataset.editAction, endpoint = '/api/bouquets/' + product.id;
  if (action === 'edit') return edit(product);
  if (action === 'remove') return showForm('Удалить букет?', '<p>«'+esc(product.name)+'» исчезнет из каталога.</p>','Удалить',async () => {
   const result = await request(endpoint,'DELETE');
   dialog.close();
   update(result,'Букет удалён.');
  });
  target.disabled = true;
  try {
   const show = product.inCatalog === false;
   update(await request(endpoint,'PUT',{...product,inCatalog:show}),show ? 'Букет снова в каталоге.' : 'Букет скрыт.');
  } catch (e) { message(e.message); }
  finally { target.disabled = false; }
 });
 request('/api/catalog').then(result => {
  update(result);
  if (!editing && location.pathname.startsWith('/admin')) login();
 }).catch(() => message('Не удалось загрузить каталог. Обнови страницу.'));
 return {
  editing: () => editing,
  controls: p => {
   if (!editing) return '';
   const button = (action,label) => '<button type="button" data-edit-id="'+esc(p.id)+'" data-edit-action="'+action+'">'+label+'</button>';
   return '<div class="florist-card">' + (p.inCatalog === false ? '<small>Скрыт от покупателей</small>' : '') +
    button('edit','Изменить') + button('toggle',p.inCatalog === false ? 'Показать' : 'Скрыть') + button('remove','Удалить') + '</div>';
  }
 };
}
