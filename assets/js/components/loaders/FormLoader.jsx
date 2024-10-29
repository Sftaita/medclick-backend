import React from "react"
import ContentLoader from "react-content-loader"

const FormLoader = (props) => (
  <ContentLoader 
    speed={2}
    width={400}
    height={150}
    viewBox="0 0 400 150"
    backgroundColor="#f3f3f3"
    foregroundColor="#ecebeb"
    {...props}
  >
    <rect x="25" y="15" rx="5" ry="5" width="367" height="17" /> 
    <rect x="25" y="70" rx="5" ry="5" width="367" height="17" /> 
    <rect x="24" y="128" rx="5" ry="5" width="367" height="17" />
  </ContentLoader>
)

export default FormLoader