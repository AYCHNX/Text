/*
 * @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
import { Editor } from 'tiptap'
import {
	HardBreak,
	Heading,
	Code,
	Link,
	OrderedList,
	ListItem,
	Blockquote,
	CodeBlock,
	HorizontalRule,
	Image,
	History
} from 'tiptap-extensions'
import { Strong, Italic, Strike } from './marks'
import MarkdownIt from 'markdown-it'
import taskLists from 'markdown-it-task-lists'
import TodoItem from './nodes/TodoItem'
import TodoList from './nodes/TodoList'
import BulletList from './nodes/BulletList'

import { MarkdownSerializer, defaultMarkdownSerializer } from 'prosemirror-markdown'

const createEditor = ({ content, onUpdate, extensions }) => {
	extensions = extensions || []
	return new Editor({
		content: content,
		onUpdate: onUpdate,
		extensions: [
			new HardBreak(),
			new Heading(),
			new Code(),
			new Strong(),
			new Italic(),
			new Strike(),
			new HorizontalRule(),
			new BulletList(),
			new OrderedList(),
			new Blockquote(),
			new CodeBlock(),
			new ListItem(),
			new Link(),
			new Image(),
			new History(),
			new TodoList(),
			new TodoItem({ nested: true })
		].concat(extensions)
	})
}

const markdownit = MarkdownIt('commonmark', { html: false, breaks: false })
	.enable('strikethrough')
	.use(taskLists, { enable: true, label: true, labelAfter: true })

const createMarkdownSerializer = (_nodes, _marks) => {
	const nodes = Object
		.entries(_nodes)
		.filter(([, node]) => node.toMarkdown)
		.reduce((items, [name, { toMarkdown }]) => ({
			...items,
			[name]: toMarkdown
		}), {})

	const marks = Object
		.entries(_marks)
		.filter(([, node]) => node.toMarkdown)
		.reduce((items, [name, { toMarkdown }]) => ({
			...items,
			[name]: toMarkdown
		}), {})
	return new MarkdownSerializer(
		{ ...defaultMarkdownSerializer.nodes, ...nodes },
		{ ...defaultMarkdownSerializer.marks, ...marks }
	)
}

export default createEditor
export { markdownit, createEditor, createMarkdownSerializer }
