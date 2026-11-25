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



// Liste prospects : la sélection de l'écran (filtres GET) est mémorisée en
// localStorage, plus aucune URL de retour ne circule par la session.
// - URL avec query (formulaire « Filtrer », pagination serveur) : sauvegarde ;
// - URL nue (retour depuis une fiche, menu) : restaure la sélection ;
// - « Réinitialiser » : efface la sélection avant de naviguer.
//
// Exécuté au chargement du script, AVANT tout handler `ready` : quand une
// restauration est nécessaire, la redirection part immédiatement et l'init
// DataTables est court-circuitée (flag __prospectsRestaure) — sinon la page
// non filtrée s'affiche, la ligne est repaginée/scrollée, puis le filtre
// arrive au second chargement.
(function () {
	const CHEMIN = '/user/prospects';
	const CLE = 'prospects.selection';
	const CLE_COURANT = 'prospects.courant';

	// Fiche consultée : mémorisée pour repositionner la liste dessus, même
	// en revenant par le menu (aucun fragment dans l'URL).
	const fiche = window.location.pathname.match(/^\/user\/prospects\/show\/(\d+)$/);
	if (fiche) {
		try { localStorage.setItem(CLE_COURANT, fiche[1]); } catch (e) { /* stockage indisponible */ }
		return;
	}

	if (window.location.pathname !== CHEMIN) return;

	// Cible de repositionnement : fragment explicite, sinon dernier prospect
	// consulté. Le fragment est retiré de l'URL immédiatement (ce script
	// s'exécute avant le rendu du corps) : le navigateur ne fait plus son saut
	// natif vers l'ancre — suivi du re-paginage DataTables qui ramenait en
	// haut — d'où le « descend puis remonte ». Un seul scroll, après pagination.
	let cible = null;
	const mHash = window.location.hash.match(/^#prospect-(\d+)$/);
	if (mHash) {
		cible = parseInt(mHash[1], 10);
		history.replaceState(null, '', CHEMIN + window.location.search);
	} else {
		let courant = null;
		try { courant = localStorage.getItem(CLE_COURANT); } catch (e) { /* stockage indisponible */ }
		if (courant) cible = parseInt(courant, 10);
	}
	window.__prospectsCible = cible;

	const query = window.location.search;
	if (query && query !== '?') {
		try { localStorage.setItem(CLE, query); } catch (e) { /* stockage indisponible */ }
		return;
	}

	let sauvegarde = null;
	try { sauvegarde = localStorage.getItem(CLE); } catch (e) { /* stockage indisponible */ }
	if (sauvegarde) {
		// Filtres à réappliquer : la page doit être re-rendue par le serveur.
		// Le fragment est conservé : il sera retiré au chargement suivant,
		// avant le rendu, donc sans saut natif.
		window.__prospectsRestaure = true;
		window.location.replace(CHEMIN + sauvegarde + (mHash ? '#prospect-' + cible : ''));
	}
})();

// La page va être remplacée : aucun init ni scroll ne doit se déclencher dessus.
$(document).ready(function () {
	if (window.__prospectsRestaure) return;

	const $table = $('#dataTables[data-datatable="prospects"]');
	const $tableGenerique = $('#dataTables').not($table);

	if (typeof $.fn.DataTable === 'undefined') return;

	// Autres listes (contacts, actions, campagnes…) : init générique
	// client-side, sans l'endpoint prospects.
	if (!$table.length) {
		if (!$tableGenerique.length) return;

		// Nettoyage des lignes de message (« aucun enregistrement », colspan) :
		// leur nombre de cellules diffère de celui des colonnes et DataTables
		// remonte l'erreur « Requested unknown parameter » sur tableau vide.
		const nbColonnes = $tableGenerique.find('thead th').length;
		$tableGenerique.find('tbody tr').each(function () {
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

		const colGen = $tableGenerique.data('order-col') ?? 0;
		const dirGen = $tableGenerique.data('order-dir') ?? 'asc';
		$tableGenerique.DataTable({
			stateSave: true,
			columnDefs: [
				{ targets: 'no-sort', orderable: false },
				{ targets: 'no-string', type: 'num' },
			],
			responsive: true,
			iDisplayLength: 100,
			order: [[colGen, dirGen]],
		});
		return;
	}

	if (!$table.length) return;
	$orderCol = $table.data('order-col') ?? 0;
	$orderDir = $table.data('order-dir') ?? 'asc';

	// Liste rendue côté serveur : filtres, tri, pagination et recherche
	// traités par app_user_prospects_data ; chaque cellule arrive en HTML.
	const colonnes = [];
	$table.find('thead th').each(function () {
		colonnes.push({
			data: String(colonnes.length),
			orderable: !this.classList.contains('no-sort'),
			width: this.style.maxWidth || this.getAttribute('width') || undefined,
		});
	});

	$table.DataTable({
		serverSide: true,
		processing: false,
		stateSave: true,
		ajax: {
			url: '/user/prospects/data',
			data: function (d) {
				// Filtres de la liste, portés par l'URL (le serveur les
				// revalide) : la recherche globale DataTables part déjà.
				const params = new URLSearchParams(window.location.search);
				['campagne', 'sprint', 'utilisateur', 'contacte', 'produit', 'cible', 'qualifie', 'statut'].forEach(function (cle) {
					const valeur = params.get(cle);
					if (valeur) d[cle] = valeur;
				});
				// Repositionnement demandé une seule fois (première requête) :
				// le serveur renvoie l'index du prospect dans la liste triée.
				if (window.__prospectsCible && !window.__prospectsPositionEnvoyee) {
					d.positionner = window.__prospectsCible;
					window.__prospectsPositionEnvoyee = true;
				}
			},
			dataSrc: function (json) {
				window.__prospectsCibleIndex = (null === json.cibleIndex || undefined === json.cibleIndex) ? null : json.cibleIndex;
				return json.data;
			},
		},
		columns: colonnes,
		autoWidth: false,
		responsive: true,
		iDisplayLength: 100,
		order: [[$orderCol, $orderDir]],
	});

	// Révélation du tableau une fois l'état final posé (pagination + scroll) :
	// l'utilisateur ne voit jamais les lignes brutes ni le saut de pagination.
	const reveler = function () {
		$('#dataTables').closest('.js-datatable-pending').removeClass('js-datatable-pending');
	};
	// Filet de sécurité : si l'init ou le repositionnement échoue, le tableau
	// ne doit pas rester invisible.
	setTimeout(reveler, 2000);

	const prospectId = window.__prospectsCible;
	if (prospectId) {
		const api = $table.DataTable();
		let repositionne = false;
		api.on('draw', function () {
			if (repositionne) return;
			const index = window.__prospectsCibleIndex;
			if (null === index) {
				// Le prospect ciblé n'existe pas ou est hors filtre : la
				// première réponse (qui portait « positionner ») est arrivée.
				if (window.__prospectsPositionEnvoyee) reveler();
				return;
			}
			repositionne = true;
			const pageCible = Math.floor(index / api.page.len());
			const positionner = function () {
				const $row = $('#prospect-' + prospectId);
				if ($row.length && $row.is(':visible')) {
					$row[0].scrollIntoView({ behavior: 'auto', block: 'center' });
					$row.addClass('table-highlight');
					setTimeout(function () { $row.removeClass('table-highlight'); }, 2000);
				}
				reveler();
			};
			if (api.page() !== pageCible) {
				api.one('draw', positionner);
				api.page(pageCible).draw('page');
			} else {
				setTimeout(positionner, 100);
			}
		});
	} else {
		reveler();
	}
});

// « Réinitialiser » : vide la sélection mémorisée avant de recharger la liste.
$(function () {
	$(document).on('click', '#filtre-reset', function () {
		try { localStorage.removeItem('prospects.selection'); } catch (e) { /* stockage indisponible */ }
	});
});

// Une action sur le tableau (cycle produit, ajout produit, cycle pipeline,
// switch « Contacté », clic action) invalide le repositionnement : on oublie
// le prospect courant et le fragment #prospect-{id} de l'URL, sinon le
// prochain chargement de la liste re-pagine et re-scrolle vers lui.
function oublierProspectCourant() {
	try { localStorage.removeItem('prospects.courant'); } catch (e) { /* stockage indisponible */ }
	if (window.location.hash) {
		history.replaceState(null, '', window.location.pathname + window.location.search);
	}
}

// Clic sur le badge « action » de la liste : navigation vers la fiche action.
$(document).on('click', '.js-oublier-courant', function () {
	oublierProspectCourant();
});

// Le contenu d'une cellule vient d'être modifié inline : en mode client le
// cache de tri de DataTables doit être invalidé. En server-side (liste), le
// tri est recalculé par le serveur à chaque draw — la mise à jour inline
// suffit jusqu'au prochain re-render, rien à invalider.
function invaliderCellule($cible) {
	if (!$.fn.DataTable || !$.fn.DataTable.isDataTable('#dataTables')) return;
	const api = $('#dataTables').DataTable();
	if (!api || !api.settings().length || api.settings()[0].oFeatures.bServerSide) return;
	api.cell($cible.closest('td')).invalidate('dom').draw(false);
}

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
		oublierProspectCourant();

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
				// Signature du contact : ligne « Contacté par » de la fiche
				// (.js-contacte-par), vidée quand le prospect repasse à
				// « non contacté ». Hors fiche, le marqueur est absent.
				if (r.data.contactePar !== undefined) {
					const $par = $('.js-contacte-par');
					if ($par.length) {
						$par.text(r.data.contactePar || '—');
					}
					const $input = $wrapper.find('.js-toggle-contacte');
					const base = 'Contacté';
					$input.attr('title', r.data.contactePar ? base + ' par ' + r.data.contactePar : base);
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

// Switch « Client » sur la fiche prospect : toggle via endpoint AJAX,
// met à jour le badge du titre (#js-customer-badge).
$(document).ready(function () {
	$(document).on('change', '.js-toggle-customer', function () {
		const $checkbox = $(this);
		const $wrapper = $checkbox.closest('.js-toggle-customer-wrapper');
		const actionId = $wrapper.data('action-id');
		const csrf = $wrapper.data('csrf');
		if (!actionId || !csrf) return;

		$checkbox.prop('disabled', true);
		const url = '/user/prospects/toggle-customer/' + actionId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);
		oublierProspectCourant();

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
				$checkbox.prop('checked', r.data.customer === true);
				$('#js-customer-badge').toggleClass('d-none', r.data.customer !== true);
			}
		}).catch(function () {
			$checkbox.prop('checked', !$checkbox.prop('checked'));
			alert('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$checkbox.prop('disabled', false);
		});
	});
});

// Cycle de la qualification produit sur les badges cliquables dans la liste prospects.
// non → oui → hors_produit → injoignable → non.
$(document).ready(function () {
	$(document).on('click', '.js-cycle-produit', function (e) {
		e.preventDefault();
		e.stopPropagation();
		const $btn = $(this);
		if ($btn.prop('disabled')) return;

		const prospectId = $btn.data('prospect');
		const produitId = $btn.data('produit');
		const csrf = $btn.data('csrf');
		if (!prospectId || !produitId || !csrf) return;

		$btn.prop('disabled', true);
		const url = '/user/prospects/toggle-produit/' + prospectId + '/' + produitId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);
		oublierProspectCourant();

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
			const newClass = 'badge text-bg-' + r.data.color + ' js-cycle-produit';
			$btn.attr('class', newClass);
			$btn.attr('title', r.data.label);
			// Liste : mise à jour inline (pas de rechargement du tableau).
			// Fiche : reload pour rafraîchir la ligne d'audit de qualification.
			if ($('#dataTables').length) {
				invaliderCellule($btn);
			} else {
				location.reload();
			}
		}).catch(function () {
			alert('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});
});

// Ajout d'un produit à un prospect via le dropdown.
$(document).ready(function () {
	$(document).on('click', '.js-link-produit', function (e) {
		e.preventDefault();
		e.stopPropagation();
		const $link = $(this);
		const prospectId = $link.data('prospect');
		const produitId = $link.data('produit');
		const csrf = $link.data('csrf');
		if (!prospectId || !produitId || !csrf) return;

		const url = '/user/prospects/add-produit/' + prospectId + '/' + produitId;
		const body = new URLSearchParams();
		body.set('_csrf_token', csrf);
		oublierProspectCourant();

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
			// Nouveau badge produit inline (qualification « Non » par défaut),
			// avec le CSRF rendu côté serveur pour ses futurs cycles. L'entrée
			// du menu est retirée ; le dropdown disparaît s'il devient vide.
			const $item = $link.closest('li');
			const $menu = $item.closest('ul');
			const $wrapper = $link.closest('.d-flex');
			const $badge = $('<button type="button" class="badge text-bg-secondary js-cycle-produit">')
				.attr('data-prospect', prospectId)
				.attr('data-produit', produitId)
				.attr('data-csrf', $link.data('csrf-produit'))
				.attr('title', 'Non')
				.text($link.text().trim());
			$badge.insertBefore($wrapper.find('.dropdown'));
			$item.remove();
			if ($menu.children('li').length === 0) {
				$wrapper.find('.dropdown').remove();
			}
			invaliderCellule($wrapper);
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
		oublierProspectCourant();

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
			body: JSON.stringify({ siren: siren, csrf_token: csrf }),
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

	// Liaison d'un prospect à un tiers Dolibarr (lecture seule côté Dolibarr).
	// La modale est chargée à la demande, la recherche reste en AJAX et la
	// liaison est un POST JSON revalidé côté serveur.
	$(document).on('click', '.js-dolibarr-open', function () {
		const $btn = $(this);
		if ($btn.prop('disabled')) return;
		const url = $btn.data('search-url');
		if (!url) return;

		$btn.prop('disabled', true);
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
			const $modal = $(html).appendTo('body');
			const modalEl = $modal.get(0);
			const modalId = modalEl.id;
			if (modalId) {
				openModals[modalId] = modalEl;
			}
			bootstrap.Modal.getOrCreateInstance(modalEl).show();
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

	// Re-recherche dans la modale Dolibarr : on remplace body + footer pour
	// rester sur la même instance Bootstrap.
	$(document).on('submit', '[id^="js-dolibarr-search-"]', function (e) {
		e.preventDefault();
		const $form = $(this);
		const searchUrl = $form.data('search-url');
		const q = $form.find('input[name="q"]').val();
		const modalEl = openModals[$form.attr('id').replace('js-dolibarr-search-', 'js-dolibarr-modal-')];
		if (!searchUrl || !modalEl) return;

		const $btn = $form.find('button[type="submit"]');
		$btn.prop('disabled', true);
		fetch(searchUrl + '?q=' + encodeURIComponent(q || ''), {
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
			const doc = new DOMParser().parseFromString(html, 'text/html');
			const newBody = doc.querySelector('.modal-body');
			const newFooter = doc.querySelector('.modal-footer');
			const $modal = $(modalEl);
			const oldBody = $modal.find('.modal-body').get(0);
			const oldFooter = $modal.find('.modal-footer').get(0);
			if (newBody && oldBody) oldBody.innerHTML = newBody.innerHTML;
			if (newFooter && oldFooter) oldFooter.innerHTML = newFooter.innerHTML;
		}).catch(function (err) {
			showError('Erreur de recherche Dolibarr : ' + (err.message || ''));
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	});

	// Liaison / retrait : POST JSON puis recharge la fiche pour refléter l'état.
	function lierDolibarr($btn, thirdpartyId) {
		const $form = $($btn.data('form'));
		const linkUrl = $form.data('link-url');
		const csrf = $form.find('input[name="_csrf_token"]').val();
		const prospectId = $form.find('input[name="prospectId"]').val();
		if (!linkUrl || !csrf || !prospectId) return;

		$btn.prop('disabled', true);
		fetch(linkUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
				'Origin': window.location.origin,
			},
			body: JSON.stringify({
				prospectId: prospectId,
				thirdpartyId: thirdpartyId,
				csrf_token: csrf,
			}),
		}).then(function (resp) {
			return resp.json().then(function (data) {
				return { ok: resp.ok, status: resp.status, data: data };
			});
		}).then(function (r) {
			if (!r.ok || !r.data.ok) {
				showError((r.data && r.data.error) ? r.data.error : ('Erreur ' + r.status));
				return;
			}
			window.location.reload();
		}).catch(function () {
			showError('Erreur réseau. Veuillez réessayer.');
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	}

	$(document).on('click', '.js-dolibarr-link', function () {
		const $btn = $(this);
		if ($btn.prop('disabled')) return;
		const $form = $($btn.data('form'));
		const id = $form.find('input[name="thirdpartyId"]:checked').val();
		if (!id) {
			showError('Sélectionnez un tiers.');
			return;
		}
		lierDolibarr($btn, id);
	});

	$(document).on('click', '.js-dolibarr-unlink', function () {
		lierDolibarr($(this), '');
	});
});
