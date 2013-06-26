/* Osmium
 * Copyright (C) 2012, 2013 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

osmium_gen_ship = function() {
	var section = $('div#nlattribs > section#ship');
	var img, h, shipname, groupname;

	if("ship" in osmium_clf && "typeid" in osmium_clf.ship) {
		groupname = osmium_types[osmium_clf.ship.typeid][5];
		shipname = osmium_types[osmium_clf.ship.typeid][1];

		img = $(document.createElement('img'));
		img.prop('src', '//image.eveonline.com/Render/' + osmium_clf.ship.typeid + '_256.png');

		osmium_loadout_can_be_submitted();

		var availslots = osmium_ship_slots[osmium_clf.ship.typeid];
		osmium_clf['X-Osmium-slots'] = {
			high: availslots[0],
			medium: availslots[1],
			low: availslots[2],
			rig: availslots[3],
			subsystem: availslots[4]
		};
		osmium_clf['X-Osmium-hardpoints'] = {
			turret: 0,
			launcher: 0
		};
	} else {
		groupname = '';
		shipname = '(No ship selected)';

		img = $(document.createElement('div'));
		img.addClass('notype');

		osmium_clf['X-Osmium-slots'] = {
			high: 0,
			medium: 0,
			low: 0,
			rig: 0,
			subsystem: 0
		};
		osmium_clf['X-Osmium-hardpoints'] = {
			turret: 0,
			launcher: 0
		};
	}

	h = $(document.createElement('h1'));
	h.append(img);
	h.append($(document.createElement('small')).text(groupname));
	h.append($(document.createElement('strong')).text(shipname));

	section.children('h1').remove();
	section.append(h);
};

osmium_init_ship = function() {
	osmium_ctxmenu_bind($("section#ship"), function() {
		var menu = osmium_ctxmenu_create();

		osmium_ctxmenu_add_option(menu, "Show ship info", function() {
			if("ship" in osmium_clf && "typeid" in osmium_clf.ship) {
				osmium_showinfo({
					new: osmium_clftoken,
					type: "ship"
				}, "..");
			} else {
				alert("No ship is selected. What are you expecting?");
			}
		}, { icon: "showinfo.png" });

		osmium_ctxmenu_add_separator(menu);

		osmium_ctxmenu_add_option(menu, "Undo (Ctrl+_)", function() {
			osmium_undo_pop();
			osmium_commit_clf();
			osmium_user_initiated_push(false);
			osmium_gen();
			osmium_user_initiated_pop();
		}, {});

		return menu;
	});

	/* This isn't pretty */
	$(document).keydown(function(e) {
		/* Chromium doesn't issue a keypress event */
		if(!e.ctrlKey || e.which != 189) return true;

		osmium_undo_pop();
		osmium_commit_clf();
		osmium_user_initiated_push(false);
		osmium_gen();
		osmium_user_initiated_pop();

		return false;
	}).keypress(function(e) {
		/* Firefox behaves as expected */
		if(!e.ctrlKey || e.which != 95) return true;

		osmium_undo_pop();
		osmium_commit_clf();
		osmium_user_initiated_push(false);
		osmium_gen();
		osmium_user_initiated_pop();

		return false;
	});
};