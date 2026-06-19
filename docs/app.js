// Initialize Mermaid Diagrams
mermaid.initialize({
  theme: "base",
  themeVariables: {
    primaryColor: "#fef2f2",
    primaryTextColor: "#0f172a",
    primaryBorderColor: "#fca5a5",
    lineColor: "#94a3b8",
    secondaryColor: "#f8fafc",
    tertiaryColor: "#ffffff"
  }
});

// Setup Code Copy Buttons for generic pre blocks
document.querySelectorAll("pre:not(.response-body-pre)").forEach((block) => {
  const button = document.createElement("button");
  button.className = "copy-button";
  button.type = "button";
  button.textContent = "Copy";
  button.addEventListener("click", async () => {
    const code = block.querySelector("code")?.innerText ?? "";
    await navigator.clipboard.writeText(code);
    button.textContent = "Copied";
    window.setTimeout(() => {
      button.textContent = "Copy";
    }, 1400);
  });
  block.appendChild(button);
});

// Generate Table of Contents (On This Page)
const sections = [...document.querySelectorAll(".doc-article section[id]")];
const toc = document.querySelector("#toc");

if (toc && sections.length) {
  sections.forEach((section) => {
    const title = section.dataset.title || section.querySelector("h2, h1")?.textContent || section.id;
    const link = document.createElement("a");
    link.href = `#${section.id}`;
    link.textContent = title;
    toc.appendChild(link);
  });
}

// Sidebar & TOC Active Scrolling Highlight
const navLinks = [...document.querySelectorAll(".docs-sidebar a, .on-this-page a")];

if (sections.length && navLinks.length) {
  const observer = new IntersectionObserver((entries) => {
    const active = entries
      .filter((entry) => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

    if (!active) return;

    navLinks.forEach((link) => {
      link.classList.toggle("active", link.getAttribute("href") === `#${active.target.id}`);
    });
  }, {
    rootMargin: "-18% 0px -68% 0px",
    threshold: [0.05, 0.2, 0.4]
  });

  sections.forEach((section) => observer.observe(section));
}

// Search Filter Sidebar Group Links
const search = document.querySelector("#docSearch");

if (search) {
  search.addEventListener("input", () => {
    const query = search.value.trim().toLowerCase();
    document.querySelectorAll(".docs-sidebar a").forEach((link) => {
      // Don't filter out sidebar mobile headers
      if (link.classList.contains("close-sidebar-btn")) return;
      link.style.display = !query || link.textContent.toLowerCase().includes(query) ? "" : "none";
    });
  });
}

// Theme Engine (Light / Dark / System Sync)
const themeButtons = document.querySelectorAll(".theme-control-btn");
let currentTheme = localStorage.getItem("docs_theme") || "system";

function applyTheme(theme) {
  const root = document.documentElement;
  
  if (theme === "dark") {
    root.setAttribute("data-theme", "dark");
  } else if (theme === "light") {
    root.removeAttribute("data-theme");
  } else {
    // System Theme resolution
    const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
    if (prefersDark) {
      root.setAttribute("data-theme", "dark");
    } else {
      root.removeAttribute("data-theme");
    }
  }
  
  // Update Segmented Button classes
  themeButtons.forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.themeVal === theme);
  });
}

// Initial Theme execution
applyTheme(currentTheme);

themeButtons.forEach((btn) => {
  btn.addEventListener("click", () => {
    currentTheme = btn.dataset.themeVal;
    localStorage.setItem("docs_theme", currentTheme);
    applyTheme(currentTheme);
  });
});

// Listen to System prefers-color-scheme changes
window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => {
  if (currentTheme === "system") {
    applyTheme("system");
  }
});

// Mobile Sidebar Drawer Menu controls
const mobileMenuBtn = document.querySelector("#mobileMenuBtn");
const closeSidebarBtn = document.querySelector("#closeSidebarBtn");
const docsSidebar = document.querySelector("#docsSidebar");
const sidebarBackdrop = document.querySelector("#sidebarBackdrop");

function openSidebarDrawer() {
  if (docsSidebar && sidebarBackdrop) {
    docsSidebar.classList.add("open");
    sidebarBackdrop.classList.add("active");
    document.body.style.overflow = "hidden"; // Prevent scrolling behind overlay
  }
}

function closeSidebarDrawer() {
  if (docsSidebar && sidebarBackdrop) {
    docsSidebar.classList.remove("open");
    sidebarBackdrop.classList.remove("active");
    document.body.style.overflow = "";
  }
}

if (mobileMenuBtn) {
  mobileMenuBtn.addEventListener("click", openSidebarDrawer);
}
if (closeSidebarBtn) {
  closeSidebarBtn.addEventListener("click", closeSidebarDrawer);
}
if (sidebarBackdrop) {
  sidebarBackdrop.addEventListener("click", closeSidebarDrawer);
}

// Auto-close drawer when navigating via link on mobile
document.querySelectorAll(".docs-sidebar a").forEach((link) => {
  link.addEventListener("click", () => {
    if (window.innerWidth <= 768) {
      closeSidebarDrawer();
    }
  });
});


