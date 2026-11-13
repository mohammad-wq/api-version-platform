  import { Link, Navigate, Route, Routes, useLocation } from "react-router-dom";
import { useAuth } from "./auth.jsx";
import LoginPage from "./pages/LoginPage.jsx";
import ProjectsPage from "./pages/ProjectsPage.jsx";
import ProjectPage from "./pages/ProjectPage.jsx";
import ProfilePage from "./pages/ProfilePage.jsx";
import { Avatar, CrumbProvider, Skeleton, useCrumbValue, usePopover } from "./components/ui.jsx";
import { Icon } from "./components/icons.jsx";
import { mode } from "./mode.js";
import { useTheme } from "./theme.js";

function Protected({ children }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) {
    return (
      <div className="boot" role="status" aria-label="Restoring session">
        <Skeleton w={44} h={44} r={12} />
        <Skeleton w={160} h={12} />
      </div>
    );
  }
  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  return children;
}

function Breadcrumbs() {
  const crumb = useCrumbValue();
  const { pathname } = useLocation();
  const onProject = pathname.startsWith("/projects/") || pathname === "/profile";
  if (mode.local) {
    return (
      <nav className="crumbs" aria-label="Breadcrumb">
        <span className="current" aria-current="page">
          {crumb ?? "…"}
        </span>
      </nav>
    );
  }
  return (
    <nav className="crumbs" aria-label="Breadcrumb">
      <Link to="/" className={onProject ? "" : "current"} aria-current={onProject ? undefined : "page"}>
        Projects
      </Link>
      {onProject && (
        <>
          <Icon name="chevron" className="crumb-sep" />
          <span className="current" aria-current="page">
            {crumb ?? "…"}
          </span>
        </>
      )}
    </nav>
  );
}

function UserMenu() {
  const { user, logout } = useAuth();
  if (mode.local) {
    return (
      <span className="stat-chip" title="Running locally from the npm package: one project, no sign-in">
        <Icon name="server" /> Local
      </span>
    );
  }
  const [open, setOpen, ref] = usePopover();
  return (
    <div className="menu-wrap" ref={ref}>
      <button className="user-btn" onClick={() => setOpen(!open)} aria-haspopup="menu" aria-expanded={open} title={user.email}>
        <Avatar name={user.email} size={28} />
        <span className="email">{user.email}</span>
        <Icon name="down" className="user-caret" />
      </button>
      {open && (
        <div className="menu menu-right" role="menu">
          <div className="menu-head">
            <Avatar name={user.email} size={34} />
            <div className="grow">
              <strong>Signed in as</strong>
              <span className="muted break">{user.email}</span>
            </div>
          </div>
          <Link className="menu-item" role="menuitem" to="/profile" onClick={() => setOpen(false)}>
            <Icon name="user" /> Profile
          </Link>
          <button className="menu-item" role="menuitem" onClick={logout}>
            <Icon name="logout" /> Log out
          </button>
        </div>
      )}
    </div>
  );
}

const THEME_OPTIONS = [
  { id: "light", label: "Light", icon: "sun" },
  { id: "dark", label: "Dark", icon: "moon" },
  { id: "system", label: "Match system", icon: "monitor" },
];

/** Light / dark / follow-the-OS picker in the top bar. */
function ThemeMenu() {
  const [theme, setTheme] = useTheme();
  const [open, setOpen, ref] = usePopover();
  const current = THEME_OPTIONS.find((option) => option.id === theme) ?? THEME_OPTIONS[0];
  return (
    <div className="menu-wrap" ref={ref}>
      <button
        type="button"
        className="icon-btn theme-btn"
        onClick={() => setOpen(!open)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Colour theme: ${current.label}`}
        title={`Theme: ${current.label}`}
      >
        <Icon name={current.icon} />
      </button>
      {open && (
        <div className="menu menu-right theme-menu" role="menu" aria-label="Colour theme">
          {THEME_OPTIONS.map((option) => (
            <button
              key={option.id}
              type="button"
              className={`menu-item${option.id === theme ? " is-selected" : ""}`}
              role="menuitemradio"
              aria-checked={option.id === theme}
              onClick={() => {
                setTheme(option.id);
                setOpen(false);
              }}
            >
              <Icon name={option.icon} /> <span className="grow">{option.label}</span>
              {option.id === theme && <Icon name="check" className="menu-check" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function Shell({ children }) {
  return (
    <>
      <a href="#main" className="skip-link">
        Skip to content
      </a>
      <header className="topbar">
        <div className="topbar-left">
          <Link to="/" className="brand" aria-label="API Version Platform home">
            <span className="brand-mark">
              <Icon name="layers" size="17px" />
            </span>
            <span className="brand-name">API Version Platform</span>
          </Link>
          <span className="topbar-divider" aria-hidden="true" />
          <Breadcrumbs />
        </div>
        <div className="topbar-right">
          <ThemeMenu />
          <UserMenu />
        </div>
      </header>
      <main className="container" id="main" tabIndex={-1}>
        {children}
      </main>
    </>
  );
}

export default function App() {
  return (
    <CrumbProvider>
      <Routes>
        <Route path="/login" element={mode.local ? <Navigate to="/" replace /> : <LoginPage />} />
        <Route
          path="/"
          element={
            <Protected>
              {mode.local ? (
                <Navigate to={`/projects/${mode.projectId}`} replace />
              ) : (
                <Shell>
                  <ProjectsPage />
                </Shell>
              )}
            </Protected>
          }
        />
        <Route
          path="/projects/:projectId"
          element={
            <Protected>
              <Shell>
                <ProjectPage />
              </Shell>
            </Protected>
          }
        />
        <Route
          path="/profile"
          element={
            mode.local ? (
              <Navigate to="/" replace />
            ) : (
              <Protected>
                <Shell>
                  <ProfilePage />
                </Shell>
              </Protected>
            )
          }
        />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </CrumbProvider>
  );
}
