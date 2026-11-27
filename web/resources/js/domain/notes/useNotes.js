import { usePage } from '@inertiajs/vue3';
import { computed, reactive, toRefs } from 'vue';
import { request } from '../../utils/http/BackendRequest.js';
import { Notes } from './NoteStore.js';
import { useUsers } from '../teams/useUsers.js';

const state = reactive({
  loading: false,
});

export const useNotes = () => {
  const { allUsers } = useUsers();
  const { loading } = toRefs(state);
  const page = usePage();
  const { projectId: rawProjectId, notes, source, project } = page.props;
  const sourceId = source?.id;
  const projectId = rawProjectId ? rawProjectId : project.id;
  const key = sourceId ? `${projectId}-${sourceId}` : projectId;
  const noteStore = Notes.by(key);

  const initNotes = () => {
    return noteStore.init(notes, allUsers);
  };

  const allNotes = computed(() => {
    return noteStore.all();
  });

  const onError = ({ response, error, message }) => {
    if (response.status >= 400 || error) {
      throw new Error(
        `${response.status} ${message} ${response.data?.message || error.message}`
      );
    }
  };

  const createNote = async (data, target) => {
    const payload = {
      ...data,
      target: target.id,
      projectId,
    };
    const { error, response } = await request({
      url: `/projects/${projectId}/notes`,
      type: 'POST',
      body: payload,
    });

    onError({ response, error, message: 'Failed to create notes.' });
    target.notes = target.notes ?? [];
    target.notes.push(response.data.note);
    noteStore.add(response.data.note);
    return { error, response };
  };

  /**
   * @param data {object} note fields with id
   * @param target {object} the item the note belongs to
   * @param options {{headers?: object}} e.g. the shared document of a co-edited memo
   */
  const updateNote = async (data, target, { headers } = {}) => {
    const { error, response } = await request({
      url: `/projects/${projectId}/notes/${data.id}`,
      type: 'PATCH',
      body: data,
      headers,
    });
    onError({ response, error, message: 'Failed to update note.' });
    target.notes = target.notes ?? [];
    const idx = target.notes.findIndex((n) => n.id === response.data.note.id);
    if (idx > -1) {
      target.notes[idx] = response.data.note;
    }
    noteStore.update(data.id, response.data.note);
    return { response, error };
  };

  const deleteNote = async (note, target) => {
    const noteId = note.id;
    const { error, response } = await request({
      url: `/projects/${projectId}/notes/${noteId}`,
      type: 'DELETE',
    });

    onError({ response, error, message: 'Failed to delete notes.' });
    target.notes = target.notes ?? [];

    const index = target.notes.findIndex((n) => n.id === noteId);
    if (index > -1) {
      target.notes.splice(index, 1);
    }

    noteStore.remove(noteId);
    return { error, response };
  };

  /**
   * A memo changed on another laptop (laptop collaboration live event).
   * @param e {{kind: 'upsert'|'delete', id: string, row?: object}}
   */
  const applyRemote = (e) => {
    const id = Number(e.id);
    if (e.kind === 'delete') {
      if (noteStore.entries[id]) noteStore.remove(id);
      return;
    }
    if (!e.row) return;
    const users = allUsers?.value ?? allUsers ?? {};
    const doc = { ...e.row, id, user: users[e.row.creating_user_id] };
    if (noteStore.entries[id]) noteStore.update(id, doc);
    else noteStore.add(doc);
  };

  return {
    notes: allNotes,
    loading,
    initNotes,
    applyRemote,
    createNoteSchema,
    editNoteSchema,
    createNote,
    updateNote,
    deleteNote,
  };
};

const createNoteSchema = ({ target, type, scope, labels }) => {
  const showLabels = labels !== false;
  return {
    type: {
      type: String,
      formType: 'hidden',
      label: null,
      optional: false,
      defaultValue: type,
    },
    target: {
      type: String,
      formType: 'hidden',
      label: null,
      optional: false,
      defaultValue: target,
    },
    scope: {
      type: String,
      label: null,
      formType: 'hidden',
      optional: true,
      defaultValue: scope,
    },
    content: {
      type: String,
      label: showLabels ? undefined : null,
      formType: 'textarea',
      autofocus: true,
      optional: false,
      placeholder: '...write your note here',
    },
    visibility: {
      type: Number,
      label: showLabels ? 'Visibility of this note' : null,
      formType: 'select',
      optional: false,
      defaultValue: 0,
      options: [
        {
          value: 0,
          label: 'Only I can see this note',
        },
        {
          value: 1,
          label: 'Team members can see this note',
        },
      ],
    },
  };
};

const editNoteSchema = ({ id, content, visibility, labels }) => {
  const showLabels = labels !== false;
  return {
    id: {
      type: String,
      formType: 'hidden',
      label: null,
      defaultValue: id,
    },
    content: {
      type: String,
      formType: 'textarea',
      label: showLabels ? undefined : null,
      defaultValue: content,
      autofocus: true,
      placeholder: '...write your note here',
    },
    visibility: {
      type: Number,
      label: showLabels ? 'Visibility of this note' : null,
      formType: 'select',
      defaultValue: visibility,
      options: [
        {
          value: 0,
          label: 'Only I can see this note',
        },
        {
          value: 1,
          label: 'Team members can see this note',
        },
      ],
    },
  };
};
