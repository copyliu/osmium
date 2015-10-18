/* Osmium
 * Copyright (C) 2015 CopyLiu <copyliu@gmail.com>
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

/*<<< require external //cdnjs.cloudflare.com/ajax/libs/i18next/1.10.2/i18next.min.js before >>>*/

$(function() {
    var i18noption = { resGetPath: '/static/locales/__lng__/__ns__.json?'+new Date().getTime(),lowerCaseLng: true,lng:'zh-CN',
        ns: {
            namespaces: ['nav', 'loadout', 'main'],
            defaultNs: 'nav'
        } };
    i18n.init(i18noption,function(){
        $("body").i18n();
    });
});
