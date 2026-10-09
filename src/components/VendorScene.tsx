import { useEffect, useRef, useState } from "react";

export function VendorScene({ subdued = false }: { subdued?: boolean }) {
  const host = useRef<HTMLDivElement>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const element = host.current;
    if (!element) return;
    let dispose: (() => void) | undefined;
    let cancelled = false;
    import("three").then((THREE) => {
      if (cancelled) return;
      const css = getComputedStyle(element);
      const color = (name: string) => css.getPropertyValue(name).trim();
      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(35, 1, 0.1, 100);
      camera.position.set(0, 0.4, 10);
      let renderer: InstanceType<typeof THREE.WebGLRenderer>;
      try { renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true }); }
      catch { return; }
      renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
      renderer.setClearColor(0, 0);
      element.appendChild(renderer.domElement);
      renderer.domElement.setAttribute("aria-label", "Animated DDLX tote bag and fashion accessories");
      const group = new THREE.Group();
      scene.add(group);
      const mint = new THREE.MeshStandardMaterial({ color: color("--scene-mint"), roughness: 0.32, metalness: 0.12 });
      const dark = new THREE.MeshStandardMaterial({ color: color("--scene-ink"), roughness: 0.6 });
      const metal = new THREE.MeshStandardMaterial({ color: color("--scene-metal"), roughness: 0.2, metalness: 0.8 });
      const shape = new THREE.Shape();
      shape.moveTo(-1.18, -1.3); shape.quadraticCurveTo(-1.4, -1.3, -1.4, -1.05);
      shape.lineTo(-1.12, 1.12); shape.quadraticCurveTo(-1.1, 1.24, -0.94, 1.24);
      shape.lineTo(0.94, 1.24); shape.quadraticCurveTo(1.1, 1.24, 1.12, 1.12);
      shape.lineTo(1.4, -1.05); shape.quadraticCurveTo(1.4, -1.3, 1.18, -1.3); shape.closePath();
      const body = new THREE.Mesh(new THREE.ExtrudeGeometry(shape, { depth: 0.55, bevelEnabled: true, bevelSegments: 5, steps: 1, bevelSize: 0.08, bevelThickness: 0.08 }), mint);
      body.position.z = -0.3;
      group.add(body);
      for (const z of [-0.28, 0.34]) {
        const curve = new THREE.CatmullRomCurve3([new THREE.Vector3(-0.65, 1.05, z), new THREE.Vector3(-0.65, 2, z), new THREE.Vector3(0, 2.37, z), new THREE.Vector3(0.65, 2, z), new THREE.Vector3(0.65, 1.05, z)]);
        group.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 40, 0.085, 10, false), mint));
      }
      const canvas = document.createElement("canvas"); canvas.width = 512; canvas.height = 256;
      const ctx = canvas.getContext("2d");
      if (ctx) {
        ctx.fillStyle = color("--scene-ink"); ctx.textAlign = "center"; ctx.font = "bold 100px Arial";
        ctx.fillText("DDLX", 256, 135); ctx.font = "22px Arial"; ctx.fillText("APPAREL", 256, 185);
        const texture = new THREE.CanvasTexture(canvas);
        const label = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 0.75), new THREE.MeshBasicMaterial({ map: texture, transparent: true }));
        label.position.set(0, -0.15, 0.34); group.add(label);
      }
      const tagMaterial = new THREE.MeshStandardMaterial({ color: color("--scene-accent"), roughness: 0.35 });
      const tag = new THREE.Mesh(new THREE.BoxGeometry(0.36, 0.68, 0.04), tagMaterial);
      tag.position.set(0.85, 0.82, 0.45); tag.rotation.z = -0.25; group.add(tag);
      const ring = new THREE.Mesh(new THREE.TorusGeometry(0.44, 0.12, 24, 64), metal);
      ring.position.set(-1.85, -0.4, 0.8); ring.rotation.set(0.45, 0.35, 0); scene.add(ring);
      scene.add(new THREE.HemisphereLight(color("--scene-light"), color("--scene-ambient"), 2.6));
      const light = new THREE.DirectionalLight(color("--scene-light"), 4); light.position.set(3, 5, 5); scene.add(light);
      const fill = new THREE.DirectionalLight(color("--scene-light"), 2); fill.position.set(-4, 2, -2); scene.add(fill);
      const pointer = { x: 0, y: 0 };
      const onMove = (e: PointerEvent) => { pointer.x = e.clientX / window.innerWidth - 0.5; pointer.y = e.clientY / window.innerHeight - 0.5; };
      window.addEventListener("pointermove", onMove);
      const resize = () => { const { width, height } = element.getBoundingClientRect(); renderer.setSize(width, height); camera.aspect = width / height; camera.updateProjectionMatrix(); };
      const observer = new ResizeObserver(resize); observer.observe(element); resize();
      const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
      let frame = 0;
      const animate = (time: number) => {
        const t = reduced.matches ? 0 : time * 0.0006;
        group.rotation.y = -0.25 + Math.sin(t) * 0.2 + (reduced.matches ? 0 : pointer.x * 0.35);
        group.rotation.z = -0.12 + Math.cos(t) * 0.035;
        group.rotation.x = reduced.matches ? 0 : pointer.y * 0.1;
        group.position.y = -0.3 + Math.sin(t * 1.5) * 0.12;
        ring.rotation.y = t * 0.6; ring.position.y = -0.4 + Math.sin(t + 1) * 0.2;
        renderer.render(scene, camera); frame = requestAnimationFrame(animate);
      };
      animate(0); setReady(true);
      dispose = () => {
        cancelAnimationFrame(frame); observer.disconnect(); window.removeEventListener("pointermove", onMove);
        scene.traverse((object) => { if (object instanceof THREE.Mesh) { object.geometry.dispose(); const materials = Array.isArray(object.material) ? object.material : [object.material]; materials.forEach((m) => { if ("map" in m && m.map instanceof THREE.Texture) m.map.dispose(); m.dispose(); }); } });
        renderer.dispose(); renderer.domElement.remove();
      };
    });
    return () => { cancelled = true; dispose?.(); };
  }, []);

  return <div ref={host} className={`vendor-scene ${subdued ? "vendor-scene-subdued" : ""}`} aria-hidden="true">{!ready && <span className="scene-fallback font-display">DDLX<span>APPAREL</span></span>}</div>;
}