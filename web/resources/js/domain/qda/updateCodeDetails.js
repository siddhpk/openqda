import { request } from '../../utils/http/BackendRequest.js';

/**
 * Persists the analytic details (kind + meta) of a code and applies them
 * to the given (reactive) code object on success.
 * @param projectId {number|string}
 * @param code {object}
 * @param values {{kind?: string, meta?: object}}
 */
export const updateCodeDetails = async (projectId, code, { kind, meta }) => {
  const body = {};
  if (kind !== undefined) body.kind = kind;
  if (meta !== undefined) body.meta = meta;
  const { response, error } = await request({
    url: route('coding.update-attribute', {
      project: projectId,
      code: code.id,
    }),
    type: 'patch',
    body,
  });
  if (error || response?.status >= 400) {
    throw new Error(
      response?.data?.message ?? error?.message ?? 'Could not save details.'
    );
  }
  const saved = response.data.code ?? {};
  code.kind = saved.kind ?? kind ?? code.kind ?? 'code';
  code.meta = saved.meta ?? meta ?? code.meta ?? {};
  return code;
};
