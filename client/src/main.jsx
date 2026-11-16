import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.jsx";
import { BASE } from "./base.js";
import "./styles.css";

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <BrowserRouter basename={BASE.replace(/\/$/, "") || "/"}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
