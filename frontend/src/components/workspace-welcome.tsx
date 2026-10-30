import Image from "next/image";
export default function WorkspaceWelcome({ title }: { title: string }) {
  return (
    <div className="workspace-welcome">
      <div className="workspace-welcome-identity">
        <Image src="/openmindai-logo.png" width={36} height={36} alt="" />
        <strong>OpenMindAI</strong>
      </div>
      <h1>{title}</h1>
    </div>
  );
}
