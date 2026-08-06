/* Utilidades del sistema — JavaScript vanilla, sin dependencias. */
(function () {
  'use strict';

  // Este archivo se carga en el <head>, antes de que exista el DOM, para que
  // las funciones ya estén definidas cuando corran los scripts en línea de las
  // vistas. Lo que necesita elementos concretos espera a DOMContentLoaded.
  document.addEventListener('DOMContentLoaded', function () {
    // --- Menú lateral en móvil ---
    var toggle = document.getElementById('toggleMenu');
    if (toggle) {
      toggle.addEventListener('click', function () {
        document.getElementById('sidebar').classList.toggle('abierto');
      });
    }
  });

  // --- Confirmación en acciones destructivas ---
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirmar]');
    if (el && !confirm(el.getAttribute('data-confirmar'))) {
      ev.preventDefault();
    }
  });

  // --- Formato de números ---
  window.fmt = function (n, dec) {
    return (parseFloat(n) || 0).toLocaleString('es-PE', {
      minimumFractionDigits: dec === undefined ? 2 : dec,
      maximumFractionDigits: dec === undefined ? 2 : dec
    });
  };

  /**
   * Autocompletado de productos.
   * @param {Object} opts input, contenedorLista, almacenId, onElegir(producto)
   */
  window.autocompletarProducto = function (opts) {
    var input = opts.input, lista = opts.lista, idx = -1, datos = [], timer = null;

    function cerrar() { lista.innerHTML = ''; idx = -1; datos = []; }

    function pintar() {
      lista.innerHTML = '';
      datos.forEach(function (p, i) {
        var div = document.createElement('div');
        if (i === idx) div.className = 'sel';
        div.innerHTML = '<strong>' + p.codigo + '</strong> — ' + p.descripcion +
                        '<span class="stock">stock: ' + fmt(p.stock) + ' ' + p.unidad + '</span>';
        div.addEventListener('mousedown', function (e) { e.preventDefault(); elegir(p); });
        lista.appendChild(div);
      });
    }

    function elegir(p) { cerrar(); input.value = ''; opts.onElegir(p); }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { cerrar(); return; }
      timer = setTimeout(function () {
        fetch(window.BASE_URL + 'api/productos.php?q=' + encodeURIComponent(q) +
              '&almacen_id=' + opts.almacenId())
          .then(function (r) { return r.json(); })
          .then(function (j) { datos = j.datos || []; idx = -1; pintar(); })
          .catch(cerrar);
      }, 220);
    });

    input.addEventListener('keydown', function (e) {
      if (!datos.length) return;
      if (e.key === 'ArrowDown') { idx = Math.min(idx + 1, datos.length - 1); pintar(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { idx = Math.max(idx - 1, 0); pintar(); e.preventDefault(); }
      else if (e.key === 'Enter') { if (idx >= 0) { elegir(datos[idx]); e.preventDefault(); } }
      else if (e.key === 'Escape') { cerrar(); }
    });

    input.addEventListener('blur', function () { setTimeout(cerrar, 150); });
  };

  /**
   * Ventana de búsqueda de productos.
   *
   * @param {Object} opts
   *   almacenId  función que devuelve el almacén vigente
   *   multiple   true para marcar varios y agregarlos de una vez
   *   exigirStock true para no permitir elegir productos sin stock (salidas)
   *   onElegir   recibe el arreglo de productos seleccionados
   */
  window.buscadorProductos = function (opts) {
    var pagina = 1, datos = [], marcados = {}, timer = null, categorias = null;

    var fondo = document.createElement('div');
    fondo.className = 'modal-fondo';
    fondo.innerHTML =
      '<div class="modal-caja" role="dialog" aria-modal="true" aria-label="Buscar producto">' +
        '<div class="modal-cab">' +
          '<h2>Buscar producto</h2>' +
          '<button type="button" class="modal-cerrar" aria-label="Cerrar">&times;</button>' +
        '</div>' +
        '<div class="modal-filtros">' +
          '<div class="campo" style="flex:1;min-width:240px">' +
            '<label>Buscar por código, descripción, categoría o marca</label>' +
            '<input type="text" class="mp-q" placeholder="Deje vacío para ver todos..." autocomplete="off">' +
          '</div>' +
          '<div class="campo">' +
            '<label>Categoría</label>' +
            '<select class="mp-cat"><option value="">Todas</option></select>' +
          '</div>' +
        '</div>' +
        '<div class="modal-cuerpo"><div class="modal-cargando">Cargando...</div></div>' +
        '<div class="modal-pie">' +
          '<span class="modal-conteo"></span>' +
          '<div class="acciones">' +
            '<button type="button" class="btn btn-sm btn-gris mp-ant">&laquo; Anterior</button>' +
            '<button type="button" class="btn btn-sm btn-gris mp-sig">Siguiente &raquo;</button>' +
            (opts.multiple ? '<button type="button" class="btn btn-sm btn-verde mp-add">Agregar seleccionados</button>' : '') +
            '<button type="button" class="btn btn-sm btn-gris mp-cerrar">Cerrar</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(fondo);

    var q      = fondo.querySelector('.mp-q'),
        selCat = fondo.querySelector('.mp-cat'),
        cuerpo = fondo.querySelector('.modal-cuerpo'),
        conteo = fondo.querySelector('.modal-conteo');

    function cerrar() {
      document.removeEventListener('keydown', alTeclado);
      fondo.remove();
    }
    function alTeclado(e) { if (e.key === 'Escape') cerrar(); }
    document.addEventListener('keydown', alTeclado);

    fondo.querySelector('.modal-cerrar').addEventListener('click', cerrar);
    fondo.querySelector('.mp-cerrar').addEventListener('click', cerrar);
    fondo.addEventListener('mousedown', function (e) { if (e.target === fondo) cerrar(); });

    function entregar(lista) {
      if (!lista.length) return;
      opts.onElegir(lista);
      if (!opts.multiple) cerrar();
    }

    function pintar() {
      if (!datos.length) {
        cuerpo.innerHTML = '<p class="vacio">No se encontraron productos con esos criterios.</p>';
        return;
      }
      var filas = datos.map(function (p) {
        var sinStock = parseFloat(p.stock) <= 0;
        var bloqueado = opts.exigirStock && sinStock;
        return '<tr class="' + (bloqueado ? 'sin-stock' : 'fila-elegible') +
                 (marcados[p.id] ? ' marcada' : '') + '" data-id="' + p.id + '">' +
          (opts.multiple ? '<td><input type="checkbox" style="width:auto"' +
             (marcados[p.id] ? ' checked' : '') + (bloqueado ? ' disabled' : '') + '></td>' : '') +
          '<td>' + p.codigo + '</td>' +
          '<td>' + p.descripcion + '</td>' +
          '<td>' + (p.categoria || '—') + '</td>' +
          '<td>' + (p.marca || '—') + '</td>' +
          '<td>' + p.unidad + '</td>' +
          '<td class="num">' + fmt(p.stock) +
            (sinStock ? ' <span class="badge badge-error">agotado</span>' : '') + '</td>' +
          '<td class="num">' + fmt(p.precio_compra) + '</td>' +
        '</tr>';
      }).join('');

      cuerpo.innerHTML =
        '<div class="tabla-scroll"><table class="tabla"><thead><tr>' +
          (opts.multiple ? '<th></th>' : '') +
          '<th>Código</th><th>Producto</th><th>Categoría</th><th>Marca</th>' +
          '<th>Und</th><th class="num">Stock</th><th class="num">P. Compra</th>' +
        '</tr></thead><tbody>' + filas + '</tbody></table></div>';

      cuerpo.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var p = datos.filter(function (x) { return String(x.id) === tr.dataset.id; })[0];
        if (opts.exigirStock && parseFloat(p.stock) <= 0) return;

        tr.addEventListener('click', function () {
          if (!opts.multiple) { entregar([p]); return; }
          if (marcados[p.id]) { delete marcados[p.id]; } else { marcados[p.id] = p; }
          tr.classList.toggle('marcada');
          var chk = tr.querySelector('input[type=checkbox]');
          if (chk) chk.checked = !!marcados[p.id];
          actualizarConteo();
        });
      });
    }

    var resumen = '';

    function actualizarConteo() {
      var n = Object.keys(marcados).length;
      conteo.textContent = resumen + (n ? '  ·  ' + n + ' seleccionado(s)' : '');
    }

    function cargar() {
      cuerpo.innerHTML = '<div class="modal-cargando">Cargando...</div>';
      var url = window.BASE_URL + 'api/productos.php?modo=lista' +
                '&q=' + encodeURIComponent(q.value.trim()) +
                '&categoria_id=' + encodeURIComponent(selCat.value) +
                '&pag=' + pagina +
                '&almacen_id=' + opts.almacenId();

      fetch(url).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { cuerpo.innerHTML = '<p class="vacio">' + (j.error || 'Error') + '</p>'; return; }
        datos = j.datos || [];

        if (categorias === null && j.categorias) {   // sólo la primera vez
          categorias = j.categorias;
          Object.keys(categorias).forEach(function (id) {
            var o = document.createElement('option');
            o.value = id; o.textContent = categorias[id];
            selCat.appendChild(o);
          });
        }
        pintar();
        resumen = j.total + ' producto(s)  ·  página ' + j.pagina + ' de ' + j.paginas;
        actualizarConteo();
        fondo.querySelector('.mp-ant').disabled = j.pagina <= 1;
        fondo.querySelector('.mp-sig').disabled = j.pagina >= j.paginas;
      }).catch(function () {
        cuerpo.innerHTML = '<p class="vacio">No se pudo consultar el catálogo.</p>';
      });
    }

    q.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { pagina = 1; cargar(); }, 250);
    });
    selCat.addEventListener('change', function () { pagina = 1; cargar(); });
    fondo.querySelector('.mp-ant').addEventListener('click', function () { if (pagina > 1) { pagina--; cargar(); } });
    fondo.querySelector('.mp-sig').addEventListener('click', function () { pagina++; cargar(); });

    if (opts.multiple) {
      fondo.querySelector('.mp-add').addEventListener('click', function () {
        var lista = Object.keys(marcados).map(function (k) { return marcados[k]; });
        if (!lista.length) { alert('Marque al menos un producto.'); return; }
        opts.onElegir(lista);
        marcados = {};
        cerrar();
      });
    }

    cargar();
    setTimeout(function () { q.focus(); }, 50);
  };

  /** Exporta una tabla HTML a CSV (abrible en Excel). RF-14. */
  window.exportarCSV = function (idTabla, nombre) {
    var filas = [];
    document.querySelectorAll('#' + idTabla + ' tr').forEach(function (tr) {
      var celdas = [];
      tr.querySelectorAll('th,td').forEach(function (c) {
        if (c.classList.contains('no-export')) return;
        celdas.push('"' + c.innerText.replace(/"/g, '""').trim() + '"');
      });
      if (celdas.length) filas.push(celdas.join(';'));
    });
    var blob = new Blob(['﻿' + filas.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (nombre || 'reporte') + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  };
})();
