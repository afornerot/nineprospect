function ModalLoad(idmodal, title, path) {
	$("#" + idmodal + " .modal-header h4").text(title);
	$("#" + idmodal + " #framemodal").attr("src", path);
}

$(document).ready(function () {
	$(document).on('select2:open', () => {
		setTimeout(() => {
			let input = document.querySelector('.select2-container--open .select2-search__field');
			if (input) input.focus();
		}, 0);
	});
});

$(document).ready(function () {
	$('.select2').select2({
		theme: 'bootstrap-5',
		templateResult: function (data) {
			if (!data.id) return data.text;

			const $result = $('<span>');
			const iconClass = $(data.element).data('icon');
			if (iconClass) {
				$result.append($('<i>').addClass(iconClass + ' me-2'));
			}
			$result.append($('<span>').text(data.text));

			return $result;
		},
		templateSelection: function (data) {
			if (!data.id) return data.text;

			const $selection = $('<span>');
			const iconClass = $(data.element).data('icon');
			if (iconClass) {
				$selection.append($('<i>').addClass(iconClass + ' me-2'));
			}
			$selection.append($('<span>').text(data.text));

			return $selection;
		}
	});
});

$(function () {
	$('[data-bs-toggle="tooltip"]').tooltip();
});



// DataTables : init automatique pour toute table #dataTables.
// Options par table via data-attributes : data-order-col (index), data-order-dir.
$(document).ready(function () {
	const $table = $('#dataTables');

	if (!$table.length || typeof $.fn.DataTable === 'undefined') return;

	// Nettoyage des lignes de message (« aucun enregistrement », colspan) :
	// leur nombre de cellules diffère de celui des colonnes et DataTables
	// remonte l'erreur « Requested unknown parameter » sur tableau vide.
	const nbColonnes = $table.find('thead th').length;
	$table.find('tbody tr').each(function () {
		const $cells = $(this).children('td, th');
		let invalide = nbColonnes > 0 && $cells.length !== nbColonnes;

		$cells.each(function () {
			if (parseInt($(this).attr('colspan') || '1', 10) > 1) {
				invalide = true;
			}
		});

		if (invalide) {
			$(this).remove();
		}
	});

	$orderCol = $table.data('order-col') ?? 0;
	$orderDir = $table.data('order-dir') ?? 'asc';

	$table.DataTable({
		columnDefs: [
			{ targets: 'no-sort', orderable: false },
			{ targets: 'no-string', type: 'num' },
		],
		responsive: true,
		iDisplayLength: 100,
		order: [[$orderCol, $orderDir]],
	});

	// Si l'URL porte un fragment #prospect-{id} (retour depuis la fiche après
	// modification), on pagine DataTables vers la page qui contient la ligne
	// correspondante — DataTables ne charge par défaut que la page courante
	// dans le DOM, donc le scroll natif du navigateur ne trouverait pas la
	// ligne si elle est sur une autre page.
	const hashMatch = (window.location.hash || '').match(/^#prospect-(\d+)$/);
	if (hashMatch) {
		const prospectId = parseInt(hashMatch[1], 10);
		// L'API DataTables est créée par $table.DataTable() ci-dessus. On
		// récupère l'instance via $.fn.dataTable.Api (évite de réinstancier).
		const api = $.fn.dataTable.Api($table);
		// Cherche la ligne par son id dans le jeu de données complet.
		const rowIdx = api.rows().indexes().filter(function (idx) {
			const node = api.row(idx).node();
			return node && node.id === 'prospect-' + prospectId;
		});
		if (rowIdx.length > 0) {
			const idx = rowIdx[0];
			const pageLen = api.page.len();
			const targetPage = Math.floor(idx / pageLen);
			// Si la page cible n'est pas la page courante, on pagine.
			if (api.page() !== targetPage) {
				api.page(targetPage).draw(false);
			}
			// Scroll après le re-render (setTimeout pour laisser DataTables finir).
			setTimeout(function () {
				const $row = $('#prospect-' + prospectId);
				if ($row.length && $row.is(':visible')) {
					$row[0].scrollIntoView({ behavior: 'auto', block: 'center' });
					$row.addClass('table-highlight');
					setTimeout(function () { $row.removeClass('table-highlight'); }, 2000);
				}
			}, 100);
		}
	}
});

// Switch « Contacté » sur la liste prospects : toggle via endpoint AJAX.
// Cycle : null → true → false → null (le switch n'affiche que true/false ;
// le clic suivant passe à null puis revient à true).
$(document).ready(function () {
	$(document).on('change', '.js-toggle-contacte', function () {
		const $checkbox = $(this);
		const $wrapper = $checkbox.closest('.js-toggle-contacte-wrapper');
		const actionId = $wrapper.data('action-id');
		const csrf = $wrapper.data('csrf');
		if (!actionId || !csrf) return;

		$checkbox.prop('disabled', true);
		const url = '/user/prospects/toggle-contacte/' + actionId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: body.toString(),
		}).then(function (resp) {
			return resp.json().then(function (data) { return { ok: resp.ok, status: resp.status, data: data }; });
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				$checkbox.prop('checked', !$checkbox.prop('checked'));
				const msg = (r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status);
				alert(msg);
			} else {
				$checkbox.prop('checked', r.data.contacte === true);
				// Mise à jour dynamique de la date du premier contact affichée
				// à côté du switch sur la fiche prospect (.js-contacte-date).
				if (r.data.datePremierContact !== undefined) {
					const $date = $wrapper.closest('dd').find('.js-contacte-date');
					if ($date.length) {
						$date.text(r.data.datePremierContact
							? formatDateFr(r.data.datePremierContact)
							: '');
					}
				}
			}
		}).catch(function () {
			$checkbox.prop('checked', !$checkbox.prop('checked'));
			alert('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$checkbox.prop('disabled', false);
		});
	});
});

// Cycle du statut Cible sur les badges cliquables dans la liste prospects.
// null → true → false → null (Non → Oui → Hors cible → Non).
$(document).ready(function () {
	$(document).on('click', '.js-cycle-cible', function (e) {
		e.preventDefault();
		e.stopPropagation();
		const $btn = $(this);
		if ($btn.prop('disabled')) return;

		const prospectId = $btn.data('prospect');
		const cibleId = $btn.data('cible');
		const csrf = $btn.data('csrf');
		if (!prospectId || !cibleId || !csrf) return;

		$btn.prop('disabled', true);
		const url = '/user/prospects/toggle-cible/' + prospectId + '/' + cibleId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: body.toString(),
		}).then(function (resp) {
			return resp.json().then(function (data) { return { ok: resp.ok, status: resp.status, data: data }; });
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				const msg = (r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status);
				alert(msg);
				return;
			}
			const newClass = 'badge text-bg-' + r.data.color + ' js-cycle-cible';
			$btn.attr('class', newClass);
			$btn.attr('title', r.data.label);
			location.reload();
		}).catch(function () {
			alert('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});
});

// Ajout d'une cible à un prospect via le dropdown.
$(document).ready(function () {
	$(document).on('click', '.js-link-cible', function (e) {
		e.preventDefault();
		e.stopPropagation();
		const $link = $(this);
		const prospectId = $link.data('prospect');
		const cibleId = $link.data('cible');
		const csrf = $link.data('csrf');
		if (!prospectId || !cibleId || !csrf) return;

		const url = '/user/prospects/add-cible/' + prospectId + '/' + cibleId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: body.toString(),
		}).then(function (resp) {
			return resp.json().then(function (data) { return { ok: resp.ok, status: resp.status, data: data }; });
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				const msg = (r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status);
				alert(msg);
				return;
			}
			location.reload();
		}).catch(function () {
			alert('Erreur réseau. Veuillez réessayer.');
		});
	});
});

// Cycle du statut d'une étape pipeline au clic sur le badge.
$(document).ready(function () {
	$(document).on('click', '.js-cycle-etape', function (e) {
		e.preventDefault();
		const $btn = $(this);
		if ($btn.prop('disabled')) return;

		const prospectId = $btn.data('prospect');
		const etapeId = $btn.data('etape');
		const csrf = $btn.data('csrf');
		if (!prospectId || !etapeId || !csrf) return;

		$btn.prop('disabled', true);
		const url = '/user/prospects/cycle-etape';
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);
		body.set('prospect', prospectId);
		body.set('etape', etapeId);

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: body.toString(),
		}).then(function (resp) {
			return resp.json().then(function (data) { return { ok: resp.ok, status: resp.status, data: data }; });
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				const msg = (r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status);
				alert(msg);
				return;
			}
			// Mise à jour visuelle : on reconstruit les classes + title + badge.
			// Le label à l'intérieur du bouton reste celui d'origine (data-static-label)
			// pour ne pas écraser le nom de l'étape ; seules la couleur (statut) et
			// l'infobulle changent.
			const newClass = 'badge text-bg-' + r.data.color + ' js-cycle-etape';
			$btn.attr('class', newClass);
			$btn.attr('title', r.data.label);
			const staticLabel = $btn.data('static-label');
			if (staticLabel !== undefined && staticLabel !== '') {
				$btn.text(staticLabel);
			} else {
				$btn.text(r.data.label);
			}
			// Si la réponse porte une date et que le bouton a explicitement été
			// marqué data-update-date (utile sur la fiche prospect où chaque
			// ligne pipeline a une colonne Date à côté du statut), on actualise
			// la cellule suivante. Sinon on n'écrase pas le contenu du <td> voisin
			// (sur la liste, ce <td> contient le switch Contacté et ne doit pas
			// être touché).
			if (r.data.date !== undefined && $btn.data('update-date')) {
				const $nextTd = $btn.closest('td').next('td');
				if ($nextTd.length) {
					$nextTd.text(r.data.date || '—');
				}
			}
		}).catch(function () {
			alert('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});
});

// Formate une date ISO (YYYY-MM-DD) en format français (DD/MM/YYYY).
// Retourne la chaîne vide si la valeur est invalide.
function formatDateFr(iso) {
	if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
	const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
	return m[3] + '/' + m[2] + '/' + m[1];
}

// Autocompletion d'adresse via l'API Adresse du gouvernement français
// (https://api-adresse.data.gouv.fr). Quand l'utilisateur saisit au moins
// 5 caractères dans #prospect_adresse, on interroge l'API et on propose
// les 5 premiers résultats ; un clic remplit rue / CP / ville / lat / lon.
$(document).ready(function () {
	if (typeof window.jQuery === 'undefined') return;
	const $ = window.jQuery;

	const $input = $('#prospect_adresse');
	if (!$input.length) return;

	// On enveloppe le champ dans un relatif pour positionner la liste.
	$input.wrap('<div class="js-address-wrapper position-relative"></div>');
	const $wrapper = $input.parent('.js-address-wrapper');

	const $list = $('<ul class="js-address-suggestions list-group shadow-sm"></ul>')
		.css({
			position: 'absolute',
			top: '100%',
			left: 0,
			right: 0,
			zIndex: 1050,
			display: 'none',
			maxHeight: '240px',
			overflowY: 'auto',
		})
		.appendTo($wrapper);

	let lastQuery = '';
	let pending = null;

	function hide() {
		$list.empty().hide();
	}

	function render(items) {
		$list.empty();
		if (!items || 0 === items.length) {
			hide();
			return;
		}
		items.forEach(function (item) {
			const props = item.properties || {};
			const coords = (item.geometry && item.geometry.coordinates) || [];
			const lon = coords[0];
			const lat = coords[1];
			const label = props.label || ((props.name || '') + ' ' + (props.postcode || '') + ' ' + (props.city || ''));

			const $li = $('<li class="list-group-item list-group-item-action js-address-item" role="button"></li>')
				.text(label)
				.data({
					rue: props.name || label,
					cp: props.postcode || '',
					ville: props.city || '',
					lat: lat,
					lon: lon,
					postcode: props.postcode || '',
				});

			$li.on('mouseenter', function () {
				$list.find('.active').removeClass('active');
				$li.addClass('active');
			});

			$li.on('mousedown', function (e) {
				// mousedown pour passer avant le blur de l'input.
				e.preventDefault();
				$input.val($li.data('rue'));
				$('#prospect_codePostal').val($li.data('cp'));
				$('#prospect_ville').val($li.data('ville'));
				if (null !== $li.data('lat')) {
					$('#prospect_latitude').val($li.data('lat'));
				}
				if (null !== $li.data('lon')) {
					$('#prospect_longitude').val($li.data('lon'));
				}
				// Pays : format Dolibarr (code ISO 3166-1 alpha-2).
				// L'API Adresse ne couvre que la France métropolitaine
				// + DOM, donc on force "FR".
				$('#prospect_pays').val('FR');
				// Département : déduit du code postal (2 chiffres en métropole,
				// 3 chiffres pour les DOM : 97x/98x).
				const cp = String($li.data('postcode') || '');
				const depcode = /^97\d|^98\d/.test(cp) ? cp.substring(0, 3) : cp.substring(0, 2);
				if (depcode.length >= 2) {
					const $dep = $('#prospect_departement');
					const $opt = $dep.find('option[data-numero="' + depcode + '"]');
					if ($opt.length) {
						$dep.val($opt.attr('value')).trigger('change');
					}
				}
				hide();
			});

			$list.append($li);
		});
		$list.show();
	}

	function search(q) {
		if (q === lastQuery) return;
		lastQuery = q;
		if (pending && pending.abort) pending.abort();
		pending = $.ajax({
			url: 'https://api-adresse.data.gouv.fr/search/',
			dataType: 'json',
			data: { q: q, limit: 5 },
		}).done(function (data) {
			if (q !== $input.val()) return; // une requête plus récente a gagné
			render((data && data.features) || []);
		}).fail(function () {
			hide();
		});
	}

	let debounceTimer = null;
	$input.on('input', function () {
		const q = $(this).val().trim();
		clearTimeout(debounceTimer);
		if (q.length < 5) {
			hide();
			lastQuery = '';
			return;
		}
		debounceTimer = setTimeout(function () { search(q); }, 250);
	});

	$input.on('focus', function () {
		const q = $(this).val().trim();
		if (q.length >= 5 && $list.children().length) {
			$list.show();
		}
	});

	$input.on('blur', function () {
		// Délai pour permettre le mousedown d'un item de la liste.
		setTimeout(hide, 150);
	});

	$input.on('keydown', function (e) {
		const $items = $list.find('.js-address-item');
		if (!$items.length) return;
		const $active = $items.filter('.active');
		const idx = $items.index($active);
		if (40 === e.which) { // flèche bas
			e.preventDefault();
			$items.removeClass('active');
			const next = idx < 0 ? 0 : Math.min(idx + 1, $items.length - 1);
			$items.eq(next).addClass('active');
		} else if (38 === e.which) { // flèche haut
			e.preventDefault();
			$items.removeClass('active');
			const prev = idx <= 0 ? 0 : idx - 1;
			$items.eq(prev).addClass('active');
		} else if (13 === e.which) { // entrée
			if (idx >= 0) {
				e.preventDefault();
				$items.eq(idx).trigger('mousedown');
			}
		} else if (27 === e.which) { // echap
			hide();
		}
	});
});

	// Bouton « Vérifier les données » sur la fiche prospect : charge la modale
	// de l'API Annuaire des Entreprises via fetch, puis applique le résultat
	// sélectionné via POST JSON avec CSRF lié à la session.
	$(document).ready(function () {
		if (typeof window.jQuery === 'undefined') return;
		const $ = window.jQuery;

		function showError(msg) {
			alert(msg);
		}

		// Réutilisée pour recharger la modale après une recherche : on
		// garde la référence à la modalEl pour pouvoir en remplacer le
		// contenu sans recréer l'instance Bootstrap.
		const openModals = {};

		$(document).on('submit', '[id^="js-verify-search-"]', function (e) {
			// Soumission du formulaire de re-recherche : on intercepte pour
			// rester en mode AJAX (pas de navigation ni fermeture modale).
			e.preventDefault();
			const $form = $(this);
			const searchUrl = $form.data('search-url');
			const q = $form.find('input[name="q"]').val();
			const modalEl = openModals[$form.attr('id').replace('js-verify-search-', 'js-verify-modal-')];
			if (!searchUrl || !modalEl) return;

			const $btn = $form.find('button[type="submit"]');
			$btn.prop('disabled', true);
			const url = searchUrl + '?q=' + encodeURIComponent(q || '');
			fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'Origin': window.location.origin,
				},
			}).then(function (resp) {
				if (!resp.ok) throw new Error('HTTP ' + resp.status);
				return resp.text();
			}).then(function (html) {
				// Remplace le contenu de la modale par le nouveau HTML.
				// On extrait modal-body + modal-footer du HTML reçu.
				const parser = new DOMParser();
				const doc = parser.parseFromString(html, 'text/html');
				const newBody = doc.querySelector('.modal-body');
				const newFooter = doc.querySelector('.modal-footer');
				const $modal = $(modalEl);
				const oldBody = $modal.find('.modal-body').get(0);
				const oldFooter = $modal.find('.modal-footer').get(0);
				if (newBody && oldBody) oldBody.innerHTML = newBody.innerHTML;
				if (newFooter && oldFooter) oldFooter.innerHTML = newFooter.innerHTML;
			}).catch(function (err) {
				showError('Erreur de recherche : ' + (err.message || ''));
			}).finally(function () {
				$btn.prop('disabled', false);
			});
		});

	$(document).on('click', '.js-verify-prospect', function () {
		const $btn = $(this);
		if ($btn.prop('disabled')) return;
		const prospectId = $btn.data('prospect');
		if (!prospectId) return;

		$btn.prop('disabled', true);
		const url = '/user/prospects/verify/' + prospectId;
		fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
		}).then(function (resp) {
			if (!resp.ok) {
				throw new Error('HTTP ' + resp.status);
			}
			return resp.text();
		}).then(function (html) {
			// Injecte la modale dans le DOM puis l'ouvre.
			const $modal = $(html).appendTo('body');
			const modalEl = $modal.get(0);
			const modalId = modalEl.id; // ex. js-verify-modal-1779
			if (modalId) {
				openModals[modalId] = modalEl;
			}
			const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
			instance.show();
			$modal.on('hidden.bs.modal', function () {
				if (modalId) delete openModals[modalId];
				$modal.remove();
			});
		}).catch(function (e) {
			showError('Erreur de chargement : ' + (e.message || ''));
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});

	// Bouton « Appliquer ce résultat » dans la modale : POST AJAX.
	$(document).on('click', '.js-verify-apply', function () {
		const $btn = $(this);
		if ($btn.prop('disabled')) return;
		const $form = $($btn.data('form'));
		const siren = $form.find('input[name="siren"]:checked').val();
		if (!siren) {
			showError('Sélectionnez un résultat.');
			return;
		}
		const csrf = $form.find('input[name="_csrf_token"]').val();
		const redirect = $form.find('input[name="redirect"]').val();
		const url = $form.data('apply-url');

		$btn.prop('disabled', true);
		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: JSON.stringify({ siren: siren, csrf_token: csrf, redirect: redirect }),
		}).then(function (resp) {
			return resp.json().then(function (data) {
				return { ok: resp.ok, status: resp.status, data: data };
			});
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				const msg = (r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status);
				showError(msg);
				return;
			}
			// Succès : redirige vers la fiche prospect.
			window.location.href = r.data.redirect || window.location.href;
		}).catch(function () {
			showError('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});
});
