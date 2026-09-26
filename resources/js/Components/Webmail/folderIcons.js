import { Archive, FileText, Folder, Inbox, Send, ShieldAlert, Star, Trash2 } from 'lucide-vue-next';

/** ADR-195 — l'icône de chaque dossier, la même dans la colonne et en tête de liste. */
export const FOLDER_ICONS = { inbox: Inbox, drafts: FileText, favorites: Star, sent: Send, archive: Archive, spam: ShieldAlert, trash: Trash2, folder: Folder };

export const folderIcon = (folder) => FOLDER_ICONS[folder?.icon] ?? Folder;
