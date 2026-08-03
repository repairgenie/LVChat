// Vendored tiptap editor for the admin legal pages. Bundled by esbuild into
// public/assets/vendor/tiptap/tiptap-bundle.js (committed like app.js), so the
// server never needs Node or internet. Exposes a single window.TiptapBundle.
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';
import TextStyle from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import TextAlign from '@tiptap/extension-text-align';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Image from '@tiptap/extension-image';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';

window.TiptapBundle = {
  Editor,
  StarterKit,
  Link,
  Placeholder,
  Underline,
  TextStyle,
  Color,
  Highlight,
  TextAlign,
  Subscript,
  Superscript,
  TaskList,
  TaskItem,
  Image,
  Table,
  TableRow,
  TableHeader,
  TableCell,
};
