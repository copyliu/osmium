/* Osmium
 * Copyright (C) 2014 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
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

$(function() {
	$("div#search_full, div#search_mini").find('input#sr').on('change', function() {
		var inp = $(this);
		var select = inp.closest('form').find('select#ss');
		if(inp.prop('checked')) {
			select.removeAttr('disabled');
		} else {
			select.attr('disabled', 'disabled');
		}
	}).change();

	$("div#search_full, div#search_mini").find('input#vr').on('change', function() {
		var inp = $(this);
		var select = inp.closest('form').find('select#vrs');
		if(inp.prop('checked')) {
			select.removeAttr('disabled');
		} else {
			select.attr('disabled', 'disabled');
		}
	}).change();
});
