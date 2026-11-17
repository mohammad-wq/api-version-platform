import { useEffect, useMemo, useState } from "react";
import { usePersistentState } from "../hooks.js";
import { baseUrlError } from "./base-url.js";
export { baseUrlError } from "./base-url.js";

export const normalizeBaseUrl = (value) => String(value ?? "").trim().replace(/\/+$/, "");

export function hostOf(value) {
  try {
    const url = new URL(String(value ?? "").trim());
    return `${url.host}${url.pathname.replace(/\/+$/, "")}`;
  } catch {
    return "";
  }
}

/** The name to show for an environment: its own name, else its host. */
export const environmentLabel = (environment) => environment?.name?.trim() || hostOf(environment?.baseUrl) || "Unnamed environment";

function defaultEnvironments(baseUrl) {
  return [{ id: "default", name: "Default", baseUrl: baseUrl ?? "" }];
}

const isEnvironment = (environment) =>
  environment && typeof environment.id === "string" && typeof environment.name === "string" && typeof environment.baseUrl === "string";

/** Spec `servers` with an absolute http(s) URL, as environments. */
export function serverEnvironments(servers) {
  const result = [];
  for (const server of Array.isArray(servers) ? servers : []) {
    const baseUrl = normalizeBaseUrl(server?.url);
    if (baseUrlError(baseUrl) || result.some((environment) => environment.baseUrl === baseUrl)) continue;
    result.push({
      id: `readme-${baseUrl.replace(/^https?:\/\//i, "").replace(/[^a-z0-9]+/gi, "-").toLowerCase()}`,
      name: String(server.description ?? "").trim().slice(0, 80) || hostOf(baseUrl),
      baseUrl,
      source: "readme",
    });
  }
  return result;
}

/**
 * Adds README servers the user has not seen yet. A blank starter list is replaced; anything the user
 * added, edited or deleted is left alone (deleted README environments are not brought back).
 */
export function mergeSpecServers(environments, seen, servers) {
  const fromSpec = serverEnvironments(servers);
  const seenUrls = new Set(Array.isArray(seen) ? seen : []);
  const nextSeen = [...new Set([...seenUrls, ...fromSpec.map((environment) => environment.baseUrl)])];
  const unseen = fromSpec.filter((environment) => !seenUrls.has(environment.baseUrl));
  const current = (Array.isArray(environments) ? environments : []).filter(isEnvironment);
  const blankStarter = current.length === 0 || (current.length === 1 && current[0].id === "default" && !current[0].baseUrl.trim());
  if (blankStarter && fromSpec.length) return { environments: fromSpec, seen: nextSeen, changed: true };
  const listed = new Set(current.map((environment) => normalizeBaseUrl(environment.baseUrl)));
  const added = unseen.filter((environment) => !listed.has(environment.baseUrl) && !current.some((item) => item.id === environment.id));
  return {
    environments: added.length ? [...current, ...added] : current,
    seen: nextSeen,
    changed: added.length > 0 || nextSeen.length !== seenUrls.size,
  };
}

/** Moves the environment with `id` one step up (-1) or down (+1). Returns the same array when it cannot move. */
export function reorderEnvironments(items, id, delta) {
  const list = Array.isArray(items) ? items : [];
  const index = list.findIndex((item) => item?.id === id);
  const target = index + (delta >= 0 ? 1 : -1);
  if (index === -1 || target < 0 || target >= list.length) return list;
  const next = [...list];
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  return next;
}

/** The spec server describing the same base URL as the environment, if any. */
export function serverFor(servers, environment) {
  const baseUrl = normalizeBaseUrl(environment?.baseUrl);
  if (!baseUrl) return null;
  return (Array.isArray(servers) ? servers : []).find((server) => normalizeBaseUrl(server?.url) === baseUrl) ?? null;
}

/** Project environments are shared through the project record; selection remains browser-local. */
export function useProjectEnvironments(project, servers, saveEnvironmentsApi) {
  const initialSharedEnvironments = Array.isArray(project.environments) ? project.environments.filter(isEnvironment) : [];
  const [environments, setEnvironments] = usePersistentState(
    `project.${project.id}.runtime-environments`,
    () => initialSharedEnvironments.length ? initialSharedEnvironments : defaultEnvironments(project.baseUrl),
  );
  const [seenServers, setSeenServers] = usePersistentState(`project.${project.id}.runtime-environments.seen`, []);
  const [selectedId, setSelectedId] = usePersistentState(
    `project.${project.id}.runtime-environment`,
    initialSharedEnvironments[0]?.id ?? "default",
  );
  const [persistedEnvironments, setPersistedEnvironments] = useState(initialSharedEnvironments);
  const sharedKey = JSON.stringify(initialSharedEnvironments);
  useEffect(() => {
    setPersistedEnvironments(initialSharedEnvironments);
    if (initialSharedEnvironments.length) setEnvironments(initialSharedEnvironments);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sharedKey]);
  const validEnvironments = Array.isArray(environments) ? environments.filter(isEnvironment) : [];
  const currentEnvironments = validEnvironments.length ? validEnvironments : defaultEnvironments(project.baseUrl);
  const active = currentEnvironments.find((environment) => environment.id === selectedId) ?? currentEnvironments[0];

  const serversKey = JSON.stringify((servers ?? []).map((server) => [server?.url, server?.description]));
  useEffect(() => {
    if (!servers?.length) return;
    const merged = mergeSpecServers(currentEnvironments, seenServers, servers);
    if (!merged.changed) return;
    setSeenServers(merged.seen);
    if (merged.environments !== currentEnvironments) {
      const replacedStarter = !merged.environments.some((environment) => environment.id === active?.id);
      setEnvironments(merged.environments);
      if (replacedStarter) setSelectedId(merged.environments[0].id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serversKey]);

  const activeServer = serverFor(servers, active);
  const runtimeProject = useMemo(
    () => ({
      ...project,
      baseUrl: normalizeBaseUrl(active?.baseUrl),
      environmentName: environmentLabel(active),
    }),
    [project, active],
  );

  const updateEnvironment = (id, patch) => {
    setEnvironments((items) => items.map((environment) => (environment.id === id ? { ...environment, ...patch } : environment)));
  };
  /** Adds an environment without switching to it; returns its id. */
  const addEnvironment = (values = {}) => {
    const id = `environment-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
    const environment = { id, name: values.name ?? "", baseUrl: values.baseUrl ?? "" };
    setEnvironments((items) => [...(Array.isArray(items) ? items.filter(isEnvironment) : []), environment]);
    return id;
  };
  const duplicateEnvironment = (id) => {
    const source = environments.find((environment) => environment.id === id);
    if (!source) return null;
    return addEnvironment({ name: `${environmentLabel(source)} copy`, baseUrl: source.baseUrl });
  };
  const removeEnvironment = (id) => {
    if (currentEnvironments.length <= 1) return;
    const remaining = currentEnvironments.filter((environment) => environment.id !== id);
    setEnvironments(remaining);
    if (id === active?.id) setSelectedId(remaining[0].id);
    return true;
  };
  /** Moves an environment one position up or down in the list. */
  const moveEnvironment = (id, delta) => {
    setEnvironments((items) => {
      const next = reorderEnvironments(items, id, delta);
      return next === items ? items : next;
    });
  };
  /** Reverts every unsaved local change back to the last saved (shared) state. */
  const resetEnvironments = () => {
    const saved = persistedEnvironments.length ? persistedEnvironments : defaultEnvironments(project.baseUrl);
    setEnvironments(saved.map((environment) => ({ ...environment })));
    if (!saved.some((environment) => environment.id === selectedId)) {
      setSelectedId(saved[0]?.id ?? "default");
    }
  };
  const hasUnsavedChanges = JSON.stringify(currentEnvironments) !== JSON.stringify(persistedEnvironments);
  const saveEnvironments = async () => {
    const next = currentEnvironments.map((environment) => {
      const error = baseUrlError(environment.baseUrl);
      if (error) throw new Error(`${environmentLabel(environment)}: ${error}`);
      return { ...environment, baseUrl: normalizeBaseUrl(environment.baseUrl) };
    });
    await saveEnvironmentsApi(project.id, next);
    setEnvironments(next);
    setPersistedEnvironments(next);
    return true;
  };

  return {
    environments: currentEnvironments,
    /* The last state saved to the project, for computing per-environment dirty flags. */
    savedEnvironments: persistedEnvironments,
    environmentsLoading: false,
    active,
    activeServer,
    runtimeProject,
    selectEnvironment: setSelectedId,
    updateEnvironment,
    renameEnvironment: (id, name) => updateEnvironment(id, { name }),
    setBaseUrl: (id, baseUrl) => updateEnvironment(id, { baseUrl }),
    /** "Skip TLS certificate check" for QA or internal servers with a self-signed certificate. Off removes the key, so it is never saved as `false`. */
    setInsecure: (id, insecure) =>
      setEnvironments((items) =>
        items.map((environment) => {
          if (environment.id !== id) return environment;
          const { insecure: _previous, ...rest } = environment;
          return insecure ? { ...rest, insecure: true } : rest;
        }),
      ),
    updateActive: (patch) => active && updateEnvironment(active.id, patch),
    addEnvironment,
    duplicateEnvironment,
    removeEnvironment,
    moveEnvironment,
    resetEnvironments,
    saveEnvironments,
    hasUnsavedChanges,
    removeActive: () => active && removeEnvironment(active.id),
  };
}
