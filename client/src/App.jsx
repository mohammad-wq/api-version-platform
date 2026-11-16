import { Link, Navigate, Route, Routes } from "react-router-dom";
import ProjectPage from "./pages/ProjectPage.jsx";
import { CrumbProvider, useCrumbValue, usePopover } from "./components/ui.jsx";
import { Icon } from "./components/icons.jsx";
import { mode } from "./mode.js";
import { useTheme } from "./theme.js";

function Breadcrumbs() {
  const crumb = useCrumbValue();
  return (
    <nav className="crumbs" aria-label="Breadcrumb">
      <span className="current" aria-current="page">
        {crumb ?? "…"}
      </span>
    </nav>
  );
}

function LocalBadge() {
  return (
    <span className="stat-chip" title="Running locally from the Composer package: one project, no sign-in">
      <Icon name="server" /> Local
    </span>
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
          <LocalBadge />
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
        <Route path="/" element={<Navigate to={`/projects/${mode.projectId}`} replace />} />
        <Route
          path="/projects/:projectId"
          element={
            <Shell>
              <ProjectPage />
            </Shell>
          }
        />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </CrumbProvider>
  );
}
